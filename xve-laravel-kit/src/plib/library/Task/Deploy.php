<?php

class Modules_XveLaravelKit_Task_Deploy extends pm_LongTask_Task
{
    const UID = 'xve-deploy';

    public $trackProgress = true;

    const STEPS = [
        'prepare'     => 'Preparing release',
        'git_clone'   => 'Cloning repository',
        'shared'      => 'Linking shared files',
        'pre_steps'   => 'Running pre-deploy steps',
        'pre_script'  => 'Running custom pre-deploy script',
        'switch'      => 'Switching to new release',
        'post_steps'  => 'Running post-deploy steps',
        'post_script' => 'Running custom post-deploy script',
        'health'      => 'Health check',
        'finalize'    => 'Finalizing',
    ];

    private $_currentStep = 'prepare';
    private $_stepStatus = [];
    private $_lockFp = null;

    /**
     * Fast pre-check for webhooks/controllers (cannot hold file locks across requests).
     */
    public static function isLocked(int $domainId): bool
    {
        return (bool) pm_Settings::get('xlk_deploy_lock_' . $domainId);
    }

    /**
     * Cancel any running deploy task for the given domain and release the lock.
     * Uses pm_LongTask_Manager::cancel() which kills the process (flock auto-releases on death).
     *
     * @return bool true if a running task was found and cancelled
     */
    public static function cancelRunning(int $domainId): bool
    {
        $taskManager = new pm_LongTask_Manager();
        // getTasks() expects the auto-generated task ID derived from the class name:
        // Modules_XveLaravelKit_Task_Deploy → task_deploy
        // Filter by domain context to avoid scanning all deploy tasks.
        try {
            $domain = pm_Domain::getByDomainId($domainId);
            $tasks = $taskManager->getTasks(['task_deploy'], [$domain]);
        } catch (\Throwable $e) {
            $tasks = $taskManager->getTasks(['task_deploy']);
        }
        $cancelled = false;

        foreach ($tasks as $task) {
            $status = $task->getStatus();
            // Double-check domainId param in case domain context filtering is imprecise
            if ($task->getParam('domainId') == $domainId
                && in_array($status, [pm_LongTask_Task::STATUS_RUNNING, pm_LongTask_Task::STATUS_NOT_STARTED], true)
            ) {
                pm_Log::info('XVE Deploy: cancelling ' . $status . ' deploy for domain ' . $domainId);
                $taskManager->cancel($task);
                $cancelled = true;
            }
        }

        // Clear the pm_Settings lock flag (flock is released when the process dies)
        if ($cancelled) {
            pm_Settings::set('xlk_deploy_lock_' . $domainId, '');
            pm_Settings::set('xlk_deploying', '');
        }

        return $cancelled;
    }

    /**
     * Atomically acquire the deploy lock using flock().
     * Also sets a pm_Settings flag so isLocked() reflects the state for other processes.
     */
    private function _tryAcquireLock(int $domainId): bool
    {
        $lockFile = '/tmp/xlk-deploy-' . $domainId . '.lock';
        $fp = fopen($lockFile, 'c');

        if ($fp === false) {
            return false;
        }

        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);
            return false;
        }

        $this->_lockFp = $fp;
        pm_Settings::set('xlk_deploy_lock_' . $domainId, '1');

        return true;
    }

    /**
     * Release the deploy lock (flock + pm_Settings flag).
     */
    private function _releaseLock(int $domainId): void
    {
        if ($this->_lockFp !== null) {
            flock($this->_lockFp, LOCK_UN);
            fclose($this->_lockFp);
            $this->_lockFp = null;
        }

        pm_Settings::set('xlk_deploy_lock_' . $domainId, '');
    }

    public function run()
    {
        $domainId = $this->getParam('domainId');
        $domain = pm_Domain::getByDomainId($domainId);
        $settings = new Modules_XveLaravelKit_DeploySettings($domain);
        $deployer = new Modules_XveLaravelKit_Deployer($domain, $settings);

        // Concurrency guard — atomic flock prevents TOCTOU race
        $lockAcquired = false;
        if (!$this->_tryAcquireLock($domainId)) {
            pm_Log::info('XVE Deploy skipped: another deploy is already running for ' . $domain->getDisplayName());
            $this->setParam('result', 'skipped');
            $this->setParam('error', 'A deploy is already in progress for this domain. Please wait for it to finish.');
            throw new \RuntimeException('A deploy is already in progress for this domain.');
        }
        $lockAcquired = true;

        // Always show banner to notify other logged-in users
        $this->_setBanner($domain->getDisplayName());

        $release = date('Ymd_His');
        $basePath = rtrim($domain->getHomePath(), '/');
        $releasePath = $basePath . '/releases/' . $release;
        $previousRelease = $settings->getCurrentRelease();

        // Initialize all steps as pending
        foreach (array_keys(self::STEPS) as $key) {
            $this->_stepStatus[$key] = 'pending';
        }
        $this->_saveStepStatus();

        try {
            // Deploy steps
            $this->_runStep('prepare', 10, function () use ($deployer) {
                $deployer->ensureStructure();
            });

            $branchOverride = $this->getParam('branch', '');
            $commitInfo = null;

            $this->_runStep('git_clone', 30, function () use ($deployer, $releasePath, $branchOverride, &$commitInfo) {
                $commitInfo = $deployer->gitClone($releasePath, $branchOverride ?: null);
                $deployer->chownRelease($releasePath);
            });

            $this->_runStep('shared', 40, function () use ($deployer, $releasePath) {
                $deployer->linkShared($releasePath);
                $deployer->ensureStorageLink($releasePath);
                $deployer->ensureArtisanSymlink();
                $deployer->ensureAppKey();
            });

            $this->_runStep('pre_steps', 55, function () use ($deployer, $releasePath) {
                $deployer->runDeploySteps('pre', $releasePath);
            });

            $this->_runStep('pre_script', 60, function () use ($deployer, $releasePath) {
                $deployer->runPreDeployScript($releasePath);
            });

            $this->_runStep('switch', 70, function () use ($deployer, $releasePath) {
                $deployer->switchRelease($releasePath);
                $deployer->fixOwnership();
            });

            $this->_runStep('post_steps', 80, function () use ($deployer, $releasePath) {
                $deployer->runDeploySteps('post', $releasePath);
            });

            $this->_runStep('post_script', 85, function () use ($deployer, $releasePath) {
                $deployer->runPostDeployScript($releasePath);
            });

            $this->_runStep('health', 90, function () use ($deployer) {
                $deployer->healthCheck();
            });

            $this->_runStep('finalize', 100, function () use ($deployer, $release, $releasePath, $settings, $commitInfo) {
                $settings->setCurrentRelease($release);
                $settings->setLastDeployTime(date('Y-m-d H:i:s'));
                $settings->setLastDeployStatus('success');
                $deployer->addHistory($release, 'deploy', 'success', $commitInfo);
                $deployer->cleanup();
            });

            $this->setParam('result', 'success');
            $this->setParam('release', $release);

            // Send Teams notification on success
            $this->_notifyTeams($settings, $domain->getDisplayName(), $release, 'success', $commitInfo);

        } catch (\Throwable $e) {
            $this->_stepStatus[$this->_currentStep] = 'error';
            $this->_saveStepStatus();

            $deployer->addHistory($release, 'deploy', 'failed', $commitInfo);
            $settings->setLastDeployTime(date('Y-m-d H:i:s'));
            $settings->setLastDeployStatus('failed');

            if ($previousRelease) {
                try {
                    $prevPath = $basePath . '/releases/' . $previousRelease;
                    $deployer->switchRelease($prevPath);
                } catch (\Throwable $rollbackError) {
                    // Log rollback failure so it is visible in Plesk logs and the UI
                    pm_Log::err('XVE Deploy rollback failed for ' . $domain->getDisplayName() . ': ' . $rollbackError->getMessage());
                    $this->setParam('rollbackError', $rollbackError->getMessage());
                    $this->_notifyTeams($settings, $domain->getDisplayName(), $release, 'rollback_failed', $commitInfo, $rollbackError->getMessage());
                }
            }

            // Park the failed release for inspection (replaces any previous failure)
            try {
                $deployer->parkFailedRelease($releasePath);
            } catch (\Throwable $cleanupError) {
                // If parking fails, fall back to removing it
                try {
                    $deployer->removeRelease($releasePath);
                } catch (\Throwable $removeError) {
                    // Best-effort cleanup
                }
            }

            $this->setParam('result', 'error');
            $this->setParam('release', $release);
            $this->setParam('error', $e->getMessage());

            // Send Teams notification on failure
            $this->_notifyTeams($settings, $domain->getDisplayName(), $release, 'failed', $commitInfo, $e->getMessage());

            throw $e;
        } finally {
            // Only release the lock if we actually acquired it — avoid clearing
            // another deploy's lock when this task was rejected by the guard.
            if ($lockAcquired) {
                $this->_releaseLock($domainId);
            }
        }
    }

    private function _runStep(string $key, int $progress, callable $fn): void
    {
        $this->_currentStep = $key;
        $this->_stepStatus[$key] = 'running';
        $this->_saveStepStatus();
        $this->updateProgress($progress > 0 ? $progress - 1 : 0);

        $fn();

        $this->_stepStatus[$key] = 'done';
        $this->_saveStepStatus();
        $this->updateProgress($progress);
    }

    private function _saveStepStatus(): void
    {
        $this->setParam('stepStatus', $this->_stepStatus);
    }

    public function statusMessage()
    {
        switch ($this->getStatus()) {
            case static::STATUS_RUNNING:
                $label = self::STEPS[$this->_currentStep] ?? 'Deploying';
                return 'Deploying: ' . $label . '...';
            case static::STATUS_DONE:
                $release = $this->getParam('release', '');
                return 'Deployment successful: release ' . $release;
            case static::STATUS_ERROR:
                return 'Deployment failed: ' . $this->getParam('error', 'Unknown error');
        }
        return '';
    }

    public function getSteps()
    {
        $stepStatus = $this->getParam('stepStatus', []);
        $steps = [];

        foreach (self::STEPS as $key => $title) {
            $status = $stepStatus[$key] ?? 'pending';

            switch ($status) {
                case 'done':
                    $progressStatus = 'Completed';
                    $progress = 100;
                    break;
                case 'running':
                    $progressStatus = 'In progress...';
                    $progress = 50;
                    break;
                case 'error':
                    $progressStatus = 'Failed';
                    $progress = 0;
                    break;
                default:
                    $progressStatus = 'Waiting';
                    $progress = 0;
                    break;
            }

            $steps[$key] = [
                'title' => $title,
                'progressStatus' => $progressStatus,
                'progress' => $progress,
            ];
        }

        return $steps;
    }

    public function onDone()
    {
        $this->_clearBanner();
        pm_Log::info('XVE Deploy task completed: ' . $this->getParam('release', ''));
    }

    public function onError(\Exception $e)
    {
        $this->_clearBanner();
        pm_Log::err('XVE Deploy task failed: ' . $e->getMessage());
    }

    private function _notifyTeams(
        Modules_XveLaravelKit_DeploySettings $settings,
        string $domainName,
        string $release,
        string $status,
        $commitInfo = null,
        string $error = ''
    ): void {
        try {
            pm_Log::info('Teams notify: checking for domain ' . $domainName . ' (status: ' . $status . ')');

            if (!$settings->isTeamsNotifyEnabled()) {
                pm_Log::info('Teams notify: disabled for this domain, skipping');
                return;
            }

            pm_Log::info('Teams notify: enabled, sending...');
            Modules_XveLaravelKit_TeamsNotifier::notifyDeploy(
                $domainName,
                $release,
                $status,
                $settings->getBranch(),
                $commitInfo,
                $error
            );
        } catch (\Throwable $e) {
            pm_Log::warning('Teams notification failed: ' . $e->getMessage());
        }
    }

    private function _setBanner(string $domainName): void
    {
        $user = 'admin';
        try {
            $user = pm_Session::getClient()->getProperty('login');
        } catch (\Throwable $e) {
            // Task may not have session context
        }

        pm_Settings::set('xlk_deploying', json_encode([
            'domain' => $domainName,
            'user' => $user,
            'started' => time(),
        ]));
    }

    private function _clearBanner(): void
    {
        pm_Settings::set('xlk_deploying', '');
    }
}
