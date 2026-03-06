<?php

class IndexController extends pm_Controller_Action
{
    protected $_accessLevel = ['admin'];

    public function init()
    {
        parent::init();
        $this->view->pageTitle = 'XVE Laravel Kit';
    }

    public function indexAction()
    {
        $this->view->domains = $this->_getDomains();
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
