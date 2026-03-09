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

    public function run()
    {
        $domainId = $this->getParam('domainId');
        $domain = pm_Domain::getByDomainId($domainId);
        $settings = new Modules_XveLaravelKit_DeploySettings($domain);
        $deployer = new Modules_XveLaravelKit_Deployer($domain, $settings);

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
            });

            $this->_runStep('pre_steps', 55, function () use ($deployer, $releasePath) {
                $deployer->runDeploySteps('pre', $releasePath);
            });

            $this->_runStep('pre_script', 60, function () use ($deployer, $releasePath) {
                $deployer->runPreDeployScript($releasePath);
            });

            $this->_runStep('switch', 70, function () use ($deployer, $releasePath) {
                $deployer->switchRelease($releasePath);
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
                $deployer->ensureArtisanSymlink();
                $deployer->ensureStorageLink($releasePath);
                $deployer->ensureAppKey();
                $deployer->fixOwnership();
            });

            $this->setParam('result', 'success');
            $this->setParam('release', $release);

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
                    // Rollback failed too
                }
            }

            $this->setParam('result', 'error');
            $this->setParam('release', $release);
            $this->setParam('error', $e->getMessage());
            throw $e;
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
