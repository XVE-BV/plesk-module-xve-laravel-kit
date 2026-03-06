<?php

class Modules_XveLaravelKit_Form_Settings extends pm_Form_Simple
{
    private $_domain;
    private $_settings;

    public function __construct(pm_Domain $domain, Modules_XveLaravelKit_DeploySettings $settings)
    {
        $this->_domain = $domain;
        $this->_settings = $settings;
        parent::__construct();
    }

    public function init()
    {
        parent::init();

        $this->addElement('text', 'git_repo', [
            'label' => 'Repository URL',
            'value' => $this->_settings->getGitRepo(),
            'required' => true,
            'validators' => [
                ['NotEmpty', true],
            ],
            'description' => 'SSH (git@github.com:user/repo.git) or HTTPS for public repos',
        ]);

        $this->addElement('text', 'branch', [
            'label' => 'Branch',
            'value' => $this->_settings->getBranch(),
            'required' => true,
            'validators' => [
                ['NotEmpty', true],
                ['Regex', false, ['pattern' => '/^[a-zA-Z0-9._\/-]+$/']],
            ],
            'description' => 'Git branch to deploy from (e.g., main, production)',
        ]);

        $this->addElement('textarea', 'shared_dirs', [
            'label' => 'Shared Directories',
            'value' => implode("\n", $this->_settings->getSharedDirs()),
            'required' => false,
            'rows' => 3,
            'description' => 'One per line. Symlinked from shared/ into each release. Persists across deploys. (e.g., storage, uploads, logs)',
        ]);

        $this->addElement('textarea', 'shared_files', [
            'label' => 'Shared Files',
            'value' => implode("\n", $this->_settings->getSharedFiles()),
            'required' => false,
            'rows' => 2,
            'description' => 'One per line. Symlinked from shared/ into each release. (e.g., .env, config/local.php)',
        ]);

        $this->addElement('select', 'node_pm', [
            'label' => 'Node Package Manager',
            'value' => $this->_settings->getNodePackageManager(),
            'multiOptions' => [
                'npm' => 'npm',
                'pnpm' => 'pnpm',
                'yarn' => 'yarn',
                'auto' => 'Auto-detect from lock file',
            ],
            'description' => 'Package manager used for "Install Node.js dependencies" and "Build frontend assets" steps',
        ]);

        $groups = [];
        foreach (Modules_XveLaravelKit_DeploySettings::STEPS as $key => $info) {
            $groups[$info['group']][$key] = $info;
        }
        foreach ($groups as $group => $steps) {
            foreach ($steps as $key => $info) {
                $this->addElement('checkbox', 'step_' . $key, [
                    'label' => $info['label'],
                    'value' => $this->_settings->isStepEnabled($key) ? '1' : '0',
                    'checked' => $this->_settings->isStepEnabled($key),
                    'description' => $info['description'],
                ]);
            }
        }

        $this->addElement('text', 'keep_releases', [
            'label' => 'Keep Releases',
            'value' => $this->_settings->getKeepReleases(),
            'required' => true,
            'validators' => [
                ['Int'],
                ['Between', false, ['min' => 1, 'max' => 20]],
            ],
            'description' => 'Number of previous releases to keep for rollback (1-20)',
        ]);

        $this->addElement('text', 'health_check_url', [
            'label' => 'Health Check URL',
            'value' => $this->_settings->getHealthCheckUrl(),
            'required' => false,
            'description' => 'URL path to check after deploy (e.g., /health). Leave empty to skip.',
        ]);

        $this->addElement('text', 'health_check_timeout', [
            'label' => 'Health Check Timeout (seconds)',
            'value' => $this->_settings->getHealthCheckTimeout(),
            'required' => false,
            'validators' => [
                ['Int'],
                ['Between', false, ['min' => 5, 'max' => 300]],
            ],
        ]);

        $this->addElement('textarea', 'pre_deploy_script', [
            'label' => 'Custom Pre-Deploy Script',
            'value' => $this->_settings->getPreDeployScript(),
            'required' => false,
            'rows' => 4,
            'description' => 'Additional bash commands to run before switching (after built-in steps)',
        ]);

        $this->addElement('textarea', 'post_deploy_script', [
            'label' => 'Custom Post-Deploy Script',
            'value' => $this->_settings->getPostDeployScript(),
            'required' => false,
            'rows' => 4,
            'description' => 'Additional bash commands to run after switching (after built-in steps)',
        ]);

        $this->addControlButtons([
            'sendTitle' => 'Save Settings',
            'cancelLink' => Modules_XveLaravelKit_Url::action('domain/index', ['domain_id' => $this->_domain->getId()]),
        ]);
    }
}
