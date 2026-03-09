<?php

class DomainController extends pm_Controller_Action
{
    protected $_accessLevel = ['admin'];

    private $_domain;
    private $_settings;
    private $_deployer;

    public function init()
    {
        parent::init();
        $domainId = $this->getRequest()->getParam('domain_id')
            ?: $this->getRequest()->getParam('dom_id');
        $this->_domain = pm_Domain::getByDomainId($domainId);
        $this->_settings = new Modules_XveLaravelKit_DeploySettings($this->_domain);
        $this->_deployer = new Modules_XveLaravelKit_Deployer($this->_domain, $this->_settings);
        $this->view->pageTitle = 'XVE Laravel Kit - ' . $this->_domain->getDisplayName();

        // Tabs — Plesk native tabbed UI
        $domainParams = ['domain_id' => $this->_domain->getId()];
        $this->view->tabs = [
            [
                'title' => 'Dashboard',
                'action' => 'index',
                'link' => Modules_XveLaravelKit_Url::action('domain/index', $domainParams),
            ],
            [
                'title' => '.env',
                'action' => 'env',
                'link' => Modules_XveLaravelKit_Url::action('domain/env', $domainParams),
            ],
            [
                'title' => 'Artisan',
                'action' => 'artisan',
                'link' => Modules_XveLaravelKit_Url::action('domain/artisan', $domainParams),
            ],
            [
                'title' => 'Log',
                'action' => 'log',
                'link' => Modules_XveLaravelKit_Url::action('domain/log', $domainParams),
            ],
            [
                'title' => 'Deploy',
                'action' => 'releases',
                'link' => Modules_XveLaravelKit_Url::action('domain/releases', $domainParams),
            ],
            [
                'title' => 'Settings',
                'action' => 'settings',
                'link' => Modules_XveLaravelKit_Url::action('domain/settings', $domainParams),
            ],
            [
                'title' => 'Guide',
                'action' => 'guide',
                'link' => Modules_XveLaravelKit_Url::action('domain/guide', $domainParams),
            ],
        ];
    }

    // ─── Dashboard Tab ─────────────────────────────────────────

    public function indexAction()
    {
        $this->view->domain = $this->_domain;
        $this->view->settings = $this->_settings;
        $this->view->appInfo = $this->_deployer->getAppInfo();
        $this->view->maintenance = $this->_deployer->isInMaintenanceMode();
        $this->view->currentRelease = $this->_settings->getCurrentRelease();
        $this->view->releaseCount = count($this->_deployer->getReleases());
    }

    // ─── .env Tab ──────────────────────────────────────────────

    public function envAction()
    {
        $this->view->domain = $this->_domain;

        if ($this->getRequest()->isPost()) {
            $contents = $this->getRequest()->getParam('env_contents');
            $forceOverride = $this->getRequest()->getParam('force_save');

            $issues = $this->_deployer->validateEnvContents($contents);
            $errors = array_filter($issues, function ($i) { return $i['level'] === 'error'; });

            // Block save if there are errors (unless force-overridden for warnings-only)
            if (!empty($errors) || (!empty($issues) && !$forceOverride)) {
                $this->view->envContents = $contents;
                $this->view->envExample = $this->_deployer->getEnvExampleContents();
                $this->view->validationIssues = $issues;
                $this->view->hasErrors = !empty($errors);
                $this->view->configCache = $this->getRequest()->getParam('config_cache');
                return;
            }

            $this->_deployer->saveEnvContents($contents);

            if ($this->getRequest()->getParam('config_cache')) {
                if (!$this->_deployer->hasCurrentRelease()) {
                    $this->_status->addMessage('info', '.env file saved. Config cache will apply after your first deploy.');
                } else {
                    $result = $this->_deployer->runArtisan('config:cache');
                    if ($result['success']) {
                        $this->_status->addMessage('info', '.env file saved and config cached.');
                    } else {
                        $this->_status->addMessage('warning', '.env file saved, but config:cache failed: ' . $result['output']);
                    }
                }
            } else {
                $this->_status->addMessage('info', '.env file saved.');
            }

            $this->_redirect('domain/env', ['domain_id' => $this->_domain->getId()]);
            return;
        }

        $this->view->envContents = $this->_deployer->getEnvContents();
        $this->view->envExample = $this->_deployer->getEnvExampleContents();
    }

    // ─── Artisan Tab ───────────────────────────────────────────

    public function artisanAction()
    {
        $this->view->domain = $this->_domain;
        $this->view->output = null;
        $this->view->command = '';

        if ($this->getRequest()->isPost()) {
            $command = trim($this->getRequest()->getParam('artisan_command', ''));
            $this->view->command = $command;
            if (!empty($command)) {
                $this->view->output = $this->_deployer->runArtisan($command);
            }
        }
    }

    // ─── Log Tab ───────────────────────────────────────────────

    public function logAction()
    {
        $this->view->domain = $this->_domain;

        if ($this->getRequest()->isPost() && $this->getRequest()->getParam('clear_log')) {
            $this->_deployer->clearLog();
            $this->_status->addMessage('info', 'Laravel log cleared.');
            $this->_redirect('domain/log', ['domain_id' => $this->_domain->getId()]);
            return;
        }

        $lines = (int) $this->getRequest()->getParam('lines', 200);
        $this->view->logContents = $this->_deployer->getLogContents($lines);
        $this->view->lines = $lines;
    }

    // ─── Releases Tab ──────────────────────────────────────────

    public function releasesAction()
    {
        $this->view->domain = $this->_domain;
        $this->view->settings = $this->_settings;
        $this->view->releases = $this->_deployer->getReleases();
        $this->view->currentRelease = $this->_settings->getCurrentRelease();
        $this->view->repoWebUrl = $this->_settings->getRepoWebUrl();
    }

    // ─── Settings Tab ──────────────────────────────────────────

    public function settingsAction()
    {
        $this->view->domain = $this->_domain;
        $this->view->isFirstSetup = !$this->_settings->isEnabled();

        try {
            $this->view->publicKey = Modules_XveLaravelKit_SshKey::getPublicKey($this->_settings);
        } catch (\Throwable $e) {
            $this->view->publicKey = '';
        }

        $secret = $this->_settings->getWebhookSecret();
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
        $this->view->webhookUrl = $scheme . '://' . $host
            . '/modules/xve-laravel-kit/public/webhook.php?secret=' . $secret;

        $form = new Modules_XveLaravelKit_Form_Settings($this->_domain, $this->_settings);

        if ($this->getRequest()->isPost() && $form->isValid($this->getRequest()->getPost())) {
            $this->_settings->setGitRepo($form->getValue('git_repo'));
            $this->_settings->setBranch($form->getValue('branch'));
            $this->_settings->setSharedDirs($form->getValue('shared_dirs'));
            $this->_settings->setSharedFiles($form->getValue('shared_files'));
            $this->_settings->setDeployMode($form->getValue('deploy_mode'));
            $this->_settings->setNodeVersion($form->getValue('node_version'));
            $this->_settings->setNodePackageManager($form->getValue('node_pm'));
            foreach (array_keys(Modules_XveLaravelKit_DeploySettings::STEPS) as $step) {
                $this->_settings->setStepEnabled($step, (bool) $form->getValue('step_' . $step));
            }
            $this->_settings->setKeepReleases((int) $form->getValue('keep_releases'));
            $this->_settings->setHealthCheckUrl($form->getValue('health_check_url'));
            $this->_settings->setHealthCheckTimeout((int) $form->getValue('health_check_timeout'));
            $this->_settings->setPreDeployScript($form->getValue('pre_deploy_script'));
            $this->_settings->setPostDeployScript($form->getValue('post_deploy_script'));
            $wasEnabled = $this->_settings->isEnabled();
            $this->_settings->setEnabled(true);
            $this->_settings->save();

            // First-time setup: create directory structure and shared files
            if (!$wasEnabled) {
                try {
                    $this->_deployer->initialize();
                    $this->_status->addMessage('info', 'Settings saved. Directory structure created — ready to deploy.');
                } catch (\Throwable $e) {
                    $this->_status->addMessage('warning', 'Settings saved, but setup failed: ' . $e->getMessage());
                }
            } else {
                $this->_status->addMessage('info', 'Settings saved.');
            }

            if ($this->getRequest()->isXmlHttpRequest()) {
                $url = Modules_XveLaravelKit_Url::action('domain/index', ['domain_id' => $this->_domain->getId()]);
                $this->_helper->json(['redirect' => $url]);
            } else {
                $this->_redirect('domain/index', ['domain_id' => $this->_domain->getId()]);
            }
            return;
        }

        $this->view->form = $form;
    }

    // ─── Actions (POST-only) ───────────────────────────────────

    public function deployAction()
    {
        if (!$this->getRequest()->isPost()) {
            $this->_redirect('domain/index', ['domain_id' => $this->_domain->getId()]);
            return;
        }

        $task = new Modules_XveLaravelKit_Task_Deploy();
        $task->setParam('domainId', $this->_domain->getId());

        $branch = trim($this->getRequest()->getParam('deploy_branch', ''));
        if (!empty($branch)) {
            $task->setParam('branch', $branch);
        }

        $taskManager = new pm_LongTask_Manager();
        $taskManager->start($task, $this->_domain);

        $this->_status->addMessage('info', 'Deployment started. You can track progress in the task bar.');
        $this->_redirect('domain/releases', ['domain_id' => $this->_domain->getId()]);
    }

    public function rollbackAction()
    {
        if (!$this->getRequest()->isPost()) {
            $this->_redirect('domain/releases', ['domain_id' => $this->_domain->getId()]);
            return;
        }

        $release = $this->getRequest()->getParam('release');

        try {
            $result = $this->_deployer->rollback($release);
            if ($result['success']) {
                $this->_status->addMessage('info', 'Rolled back to release: ' . $release);
            } else {
                $this->_status->addMessage('error', 'Rollback failed: ' . $result['error']);
            }
        } catch (\Throwable $e) {
            $this->_status->addMessage('error', 'Rollback error: ' . $e->getMessage());
        }

        $this->_redirect('domain/releases', ['domain_id' => $this->_domain->getId()]);
    }

    public function cleanAction()
    {
        if (!$this->getRequest()->isPost()) {
            $this->_redirect('domain/releases', ['domain_id' => $this->_domain->getId()]);
            return;
        }

        try {
            $removed = $this->_deployer->cleanFailedReleases();
            $this->_status->addMessage('info', 'Cleaned up ' . $removed . ' old release(s).');
        } catch (\Throwable $e) {
            $this->_status->addMessage('error', 'Cleanup error: ' . $e->getMessage());
        }

        $this->_redirect('domain/releases', ['domain_id' => $this->_domain->getId()]);
    }

    public function deleteAction()
    {
        if (!$this->getRequest()->isPost()) {
            $this->_redirect('domain/index', ['domain_id' => $this->_domain->getId()]);
            return;
        }

        $errors = [];

        // Remove filesystem: releases, shared, current symlink, history
        try {
            $this->_deployer->teardown();
        } catch (\Throwable $e) {
            $errors[] = 'Filesystem cleanup: ' . $e->getMessage();
        }

        // Remove SSH deploy keys
        try {
            $keyDir = $this->_settings->getSshKeyDir();
            if (is_dir($keyDir)) {
                Modules_XveLaravelKit_SshKey::remove($this->_settings);
            }
        } catch (\Throwable $e) {
            $errors[] = 'SSH key removal: ' . $e->getMessage();
        }

        // Remove settings
        try {
            $this->_settings->delete();
        } catch (\Throwable $e) {
            $errors[] = 'Settings removal: ' . $e->getMessage();
        }

        if (!empty($errors)) {
            $this->_status->addMessage('warning', 'Setup removed with warnings: ' . implode('; ', $errors));
        } else {
            $this->_status->addMessage('info', 'Deployment setup completely removed for ' . $this->_domain->getDisplayName() . '.');
        }

        $url = Modules_XveLaravelKit_Url::action('index/index');
        $this->getResponse()->setRedirect($url, 302)->sendResponse();
        exit;
    }

    public function maintenanceAction()
    {
        if (!$this->getRequest()->isPost()) {
            $this->_redirect('domain/index', ['domain_id' => $this->_domain->getId()]);
            return;
        }

        $action = $this->getRequest()->getParam('maintenance_action');
        $result = $this->_deployer->runArtisan($action === 'down' ? 'down' : 'up');

        if ($result['success']) {
            $this->_status->addMessage('info', 'Maintenance mode ' . ($action === 'down' ? 'enabled' : 'disabled') . '.');
        } else {
            $this->_status->addMessage('error', 'Failed: ' . $result['output']);
        }

        $this->_redirect('domain/index', ['domain_id' => $this->_domain->getId()]);
    }

    public function branchesAction()
    {
        $this->_helper->layout->disableLayout();
        $this->_helper->viewRenderer->setNoRender();

        try {
            $branches = $this->_deployer->listRemoteBranches();
            $this->getResponse()
                ->setHeader('Content-Type', 'application/json')
                ->setBody(json_encode(['branches' => $branches]));
        } catch (\Throwable $e) {
            $this->getResponse()
                ->setHttpResponseCode(500)
                ->setHeader('Content-Type', 'application/json')
                ->setBody(json_encode(['error' => $e->getMessage()]));
        }
    }

    public function deployStatusAction()
    {
        $this->_helper->layout->disableLayout();
        $this->_helper->viewRenderer->setNoRender();

        // Use the deploy banner setting as a lightweight indicator
        $deploying = pm_Settings::get('xlk_deploying', '');
        $isRunning = false;

        if (!empty($deploying)) {
            $info = json_decode($deploying, true);
            // Check if the deploy is for this domain
            if ($info && isset($info['domain'])
                && $info['domain'] === $this->_domain->getDisplayName()) {
                $isRunning = true;
            }
        }

        $this->getResponse()
            ->setHeader('Content-Type', 'application/json')
            ->setBody(json_encode([
                'deploying' => $isRunning,
            ]));
    }

    public function checkAction()
    {
        $this->view->domain = $this->_domain;
        $this->view->settings = $this->_settings;
        $this->view->checks = $this->_deployer->checkPrerequisites();
    }

    public function guideAction()
    {
        $this->view->domain = $this->_domain;
    }

    public function historyAction()
    {
        $this->view->domain = $this->_domain;
        $this->view->history = $this->_deployer->getHistory();
        $this->view->repoWebUrl = $this->_settings->getRepoWebUrl();
    }

    protected function _redirect($action, $params = [])
    {
        $url = Modules_XveLaravelKit_Url::action($action, $params);
        $this->getResponse()->setRedirect($url, 302)->sendResponse();
        exit;
    }
}
