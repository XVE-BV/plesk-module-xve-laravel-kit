<?php

class IndexController extends pm_Controller_Action
{
    protected $_accessLevel = ['admin'];

    public function init()
    {
        parent::init();
        $this->view->pageTitle = 'XVE Laravel Kit';
    }

    public function settingsAction()
    {
        if ($this->getRequest()->isPost()) {
            $action = $this->getRequest()->getParam('action', 'save');

            if ($action === 'test') {
                $url = Modules_XveLaravelKit_TeamsNotifier::getWebhookUrl();
                if (empty($url)) {
                    $this->_status->addMessage('error', 'No webhook URL configured. Save settings first.');
                } else {
                    try {
                        Modules_XveLaravelKit_TeamsNotifier::notifyDeploy(
                            'test.example.com',
                            date('Ymd_His'),
                            'success',
                            'main',
                            ['hash' => 'abc1234', 'message' => 'Test notification from XVE Laravel Kit', 'author' => 'XVE Laravel Kit']
                        );
                        $this->_status->addMessage('info', 'Test notification sent. Check your Teams channel.');
                    } catch (\Throwable $e) {
                        $this->_status->addMessage('error', 'Failed to send test notification: ' . $e->getMessage());
                    }
                }
            } else {
                $webhookUrl = trim($this->getRequest()->getParam('teams_webhook_url', ''));
                Modules_XveLaravelKit_TeamsNotifier::setWebhookUrl($webhookUrl);
                $this->_status->addMessage('info', 'Settings saved.');
            }

            $url = Modules_XveLaravelKit_Url::action('index/settings');
            $this->getResponse()->setRedirect($url, 302)->sendResponse();
            exit;
        }

        $this->view->teamsWebhookUrl = Modules_XveLaravelKit_TeamsNotifier::getWebhookUrl();
    }

    public function guideAction()
    {
        // Static page — no data needed
    }

    public function indexAction()
    {
        $this->view->domains = $this->_getDomains();

        // Populate Node.js version options for the quick-setup form
        $nodeVersionOptions = ['system' => 'System default'];
        foreach (Modules_XveLaravelKit_DeploySettings::getAvailableNodeVersions() as $ver => $label) {
            $nodeVersionOptions[$ver] = 'Node.js ' . $label . ' (Toolkit)';
        }
        $this->view->nodeVersionOptions = $nodeVersionOptions;
    }

    public function createAction()
    {
        if (!$this->getRequest()->isPost()) {
            $url = Modules_XveLaravelKit_Url::action('index/index');
            $this->getResponse()->setRedirect($url, 302)->sendResponse();
            exit;
        }

        $domainName = trim($this->getRequest()->getParam('domain_name', ''));
        $gitRepo = trim($this->getRequest()->getParam('git_repo', ''));
        $branch = trim($this->getRequest()->getParam('branch', '')) ?: 'main';
        $nodePm = trim($this->getRequest()->getParam('node_pm', 'npm'));
        $nodeVersion = trim($this->getRequest()->getParam('node_version', 'system'));

        if (empty($domainName) || empty($gitRepo)) {
            $this->_status->addMessage('error', 'Domain name and Git repository are required.');
            $url = Modules_XveLaravelKit_Url::action('index/index');
            $this->getResponse()->setRedirect($url, 302)->sendResponse();
            exit;
        }

        // Validate domain name format
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/', $domainName)) {
            $this->_status->addMessage('error', 'Invalid domain name format.');
            $url = Modules_XveLaravelKit_Url::action('index/index');
            $this->getResponse()->setRedirect($url, 302)->sendResponse();
            exit;
        }

        // Generate a system user login from the domain name
        $login = preg_replace('/[^a-z0-9]/', '', strtolower(explode('.', $domainName)[0]));
        $login = substr($login, 0, 12) ?: 'webuser';
        $password = bin2hex(random_bytes(12)) . 'A1!';

        // 1. Create Plesk subscription
        try {
            $ip = $this->_getServerIp();
            $cmd = sprintf(
                'plesk bin subscription --create %s -owner admin -login %s -passwd %s -ip %s -hosting true 2>&1',
                escapeshellarg($domainName),
                escapeshellarg($login),
                escapeshellarg($password),
                escapeshellarg($ip)
            );
            pm_ApiCli::callSbin('xve-exec.sh', [$cmd]);
        } catch (\Throwable $e) {
            $this->_status->addMessage('error', 'Failed to create subscription: ' . $e->getMessage());
            $url = Modules_XveLaravelKit_Url::action('index/index');
            $this->getResponse()->setRedirect($url, 302)->sendResponse();
            exit;
        }

        // 2. Get the new domain object
        try {
            $domain = pm_Domain::getByName($domainName);
        } catch (\Throwable $e) {
            $this->_status->addMessage('warning', 'Subscription created, but could not find domain: ' . $e->getMessage());
            $url = Modules_XveLaravelKit_Url::action('index/index');
            $this->getResponse()->setRedirect($url, 302)->sendResponse();
            exit;
        }

        // 3. Configure XVE Laravel Kit
        $settings = new Modules_XveLaravelKit_DeploySettings($domain);
        $settings->setGitRepo($gitRepo);
        $settings->setBranch($branch);
        $settings->setNodePackageManager($nodePm);
        $settings->setNodeVersion($nodeVersion);
        $settings->setSharedDirs(Modules_XveLaravelKit_DeploySettings::DEFAULT_SHARED_DIRS);
        $settings->setSharedFiles(Modules_XveLaravelKit_DeploySettings::DEFAULT_SHARED_FILES);
        $settings->setDeployMode('quiet');

        // Enable default deploy steps
        foreach (array_keys(Modules_XveLaravelKit_DeploySettings::STEPS) as $step) {
            $settings->setStepEnabled($step, true);
        }
        $settings->setKeepReleases(5);
        $settings->setEnabled(true);

        // 4. Generate SSH key & initialize directory structure
        $deployer = new Modules_XveLaravelKit_Deployer($domain, $settings);
        try {
            Modules_XveLaravelKit_SshKey::ensure($settings);
            $deployer->initialize();
        } catch (\Throwable $e) {
            $this->_status->addMessage('warning', 'Site created and configured, but initialization had issues: ' . $e->getMessage());
            $url = Modules_XveLaravelKit_Url::action('domain/settings', ['domain_id' => $domain->getId()]);
            $this->getResponse()->setRedirect($url, 302)->sendResponse();
            exit;
        }

        $this->_status->addMessage('info', 'Laravel site "' . $domainName . '" created and ready. Add the deploy key to your repository, then deploy.');
        $url = Modules_XveLaravelKit_Url::action('domain/settings', ['domain_id' => $domain->getId()]);
        $this->getResponse()->setRedirect($url, 302)->sendResponse();
        exit;
    }

    private function _getServerIp()
    {
        try {
            $ips = pm_ServerIPAddress::getList();
            foreach ($ips as $ip) {
                if ($ip->isLocal() || $ip->getAddress() === '127.0.0.1') {
                    continue;
                }
                return $ip->getAddress();
            }
            // Fallback: return first available
            foreach ($ips as $ip) {
                return $ip->getAddress();
            }
        } catch (\Throwable $e) {}

        return '127.0.0.1';
    }

    protected function _getDomains()
    {
        $domains = [];
        foreach (pm_Domain::getAllDomains() as $domain) {
            $settings = new Modules_XveLaravelKit_DeploySettings($domain);
            $info = [
                'id' => $domain->getId(),
                'name' => $domain->getDisplayName(),
                'enabled' => $settings->isEnabled(),
                'lastDeploy' => $settings->getLastDeployTime(),
                'lastStatus' => $settings->getLastDeployStatus(),
                'currentRelease' => $settings->getCurrentRelease(),
                'branch' => $settings->getBranch(),
                'appInfo' => null,
            ];

            if ($settings->isEnabled()) {
                $deployer = new Modules_XveLaravelKit_Deployer($domain, $settings);
                $info['appInfo'] = $deployer->getAppInfo();
                $info['maintenance'] = $deployer->isInMaintenanceMode();
                $info['releaseCount'] = count($deployer->getReleases());
            }

            $domains[] = $info;
        }
        return $domains;
    }
}
