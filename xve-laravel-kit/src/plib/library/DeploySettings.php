<?php

class Modules_XveLaravelKit_DeploySettings
{
    private $_domain;
    private $_prefix;

    public function __construct(pm_Domain $domain)
    {
        $this->_domain = $domain;
        $this->_prefix = 'xlk_' . $domain->getId() . '_';
    }

    public function getDomain()
    {
        return $this->_domain;
    }

    public function isEnabled()
    {
        return (bool) pm_Settings::get($this->_prefix . 'enabled', false);
    }

    public function setEnabled($value)
    {
        pm_Settings::set($this->_prefix . 'enabled', $value ? '1' : '0');
    }

    public function getGitRepo()
    {
        return pm_Settings::get($this->_prefix . 'git_repo', '');
    }

    public function setGitRepo($value)
    {
        pm_Settings::set($this->_prefix . 'git_repo', $value);
    }

    public function getBranch()
    {
        return pm_Settings::get($this->_prefix . 'branch', 'main');
    }

    public function setBranch($value)
    {
        pm_Settings::set($this->_prefix . 'branch', $value);
    }

    public function getKeepReleases()
    {
        return (int) pm_Settings::get($this->_prefix . 'keep_releases', 5);
    }

    public function setKeepReleases($value)
    {
        pm_Settings::set($this->_prefix . 'keep_releases', max(1, min(20, (int) $value)));
    }

    public function getHealthCheckUrl()
    {
        return pm_Settings::get($this->_prefix . 'health_check_url', '');
    }

    public function setHealthCheckUrl($value)
    {
        pm_Settings::set($this->_prefix . 'health_check_url', $value);
    }

    public function getHealthCheckTimeout()
    {
        return (int) pm_Settings::get($this->_prefix . 'health_check_timeout', 30);
    }

    public function setHealthCheckTimeout($value)
    {
        pm_Settings::set($this->_prefix . 'health_check_timeout', max(5, min(300, (int) $value)));
    }

    public function getPreDeployScript()
    {
        return pm_Settings::get($this->_prefix . 'pre_deploy_script', '');
    }

    public function setPreDeployScript($value)
    {
        pm_Settings::set($this->_prefix . 'pre_deploy_script', $value);
    }

    public function getPostDeployScript()
    {
        return pm_Settings::get($this->_prefix . 'post_deploy_script', '');
    }

    public function setPostDeployScript($value)
    {
        pm_Settings::set($this->_prefix . 'post_deploy_script', $value);
    }

    public function getCurrentRelease()
    {
        return pm_Settings::get($this->_prefix . 'current_release', '');
    }

    public function setCurrentRelease($value)
    {
        pm_Settings::set($this->_prefix . 'current_release', $value);
    }

    public function getLastDeployTime()
    {
        return pm_Settings::get($this->_prefix . 'last_deploy_time', '');
    }

    public function setLastDeployTime($value)
    {
        pm_Settings::set($this->_prefix . 'last_deploy_time', $value);
    }

    public function getLastDeployStatus()
    {
        return pm_Settings::get($this->_prefix . 'last_deploy_status', '');
    }

    public function setLastDeployStatus($value)
    {
        pm_Settings::set($this->_prefix . 'last_deploy_status', $value);
    }

    // -- Webhook --

    public function getWebhookSecret()
    {
        $secret = pm_Settings::get($this->_prefix . 'webhook_secret', '');
        if (empty($secret)) {
            $secret = bin2hex(random_bytes(32));
            pm_Settings::set($this->_prefix . 'webhook_secret', $secret);
        }
        return $secret;
    }

    public function regenerateWebhookSecret()
    {
        $secret = bin2hex(random_bytes(32));
        pm_Settings::set($this->_prefix . 'webhook_secret', $secret);
        return $secret;
    }

    public static function findByWebhookSecret($secret)
    {
        if (empty($secret) || strlen($secret) < 32) {
            return null;
        }
        foreach (pm_Domain::getAllDomains() as $domain) {
            $settings = new self($domain);
            if ($settings->isEnabled()) {
                $storedSecret = pm_Settings::get($settings->_prefix . 'webhook_secret', '');
                if (!empty($storedSecret) && hash_equals($storedSecret, $secret)) {
                    return $settings;
                }
            }
        }
        return null;
    }

    // -- Teams notification --

    public function isTeamsNotifyEnabled()
    {
        return (bool) pm_Settings::get($this->_prefix . 'teams_notify', false);
    }

    public function setTeamsNotifyEnabled($value)
    {
        pm_Settings::set($this->_prefix . 'teams_notify', $value ? '1' : '0');
    }

    // -- Deploy mode --

    const DEPLOY_MODES = ['normal', 'quiet'];

    public function getDeployMode()
    {
        return pm_Settings::get($this->_prefix . 'deploy_mode', 'normal');
    }

    public function setDeployMode($value)
    {
        if (!in_array($value, self::DEPLOY_MODES, true)) {
            $value = 'normal';
        }
        pm_Settings::set($this->_prefix . 'deploy_mode', $value);
    }

    // -- Node package manager --

    const PACKAGE_MANAGERS = ['auto', 'npm', 'pnpm', 'yarn'];

    public function getNodePackageManager()
    {
        return pm_Settings::get($this->_prefix . 'node_pm', 'npm');
    }

    public function setNodePackageManager($value)
    {
        if (!in_array($value, self::PACKAGE_MANAGERS, true)) {
            $value = 'npm';
        }
        pm_Settings::set($this->_prefix . 'node_pm', $value);
    }

    // -- Node.js version (Plesk Node.js Toolkit) --

    public function getNodeVersion()
    {
        return pm_Settings::get($this->_prefix . 'node_version', 'system');
    }

    public function setNodeVersion($value)
    {
        pm_Settings::set($this->_prefix . 'node_version', $value);
    }

    /**
     * Get the bin directory for the selected Node.js version.
     * Returns empty string for 'system' (use whatever is in PATH).
     */
    public function getNodeBinDir()
    {
        $version = $this->getNodeVersion();
        if ($version === 'system' || empty($version)) {
            return '';
        }
        $dir = '/opt/plesk/node/' . $version . '/bin';
        return $dir;
    }

    /**
     * Discover installed Node.js versions from the Plesk Node.js Toolkit.
     * Returns array like ['22' => '22 (/opt/plesk/node/22/bin/node)', ...]
     */
    public static function getAvailableNodeVersions()
    {
        $versions = [];
        $baseDir = '/opt/plesk/node';
        if (!is_dir($baseDir)) {
            return $versions;
        }
        $dirs = @scandir($baseDir);
        if (!is_array($dirs)) {
            return $versions;
        }
        foreach ($dirs as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $nodeBin = $baseDir . '/' . $entry . '/bin/node';
            if (file_exists($nodeBin)) {
                $versions[$entry] = $entry;
            }
        }
        ksort($versions, SORT_NUMERIC);
        return $versions;
    }

    // -- Shared directories / files --

    const DEFAULT_SHARED_DIRS = "storage\nlogs";
    const DEFAULT_SHARED_FILES = ".env";

    public function getSharedDirs()
    {
        $raw = pm_Settings::get($this->_prefix . 'shared_dirs', self::DEFAULT_SHARED_DIRS);
        return array_filter(array_map('trim', explode("\n", $raw)));
    }

    public function setSharedDirs($value)
    {
        pm_Settings::set($this->_prefix . 'shared_dirs', $value);
    }

    public function getSharedFiles()
    {
        $raw = pm_Settings::get($this->_prefix . 'shared_files', self::DEFAULT_SHARED_FILES);
        return array_filter(array_map('trim', explode("\n", $raw)));
    }

    public function setSharedFiles($value)
    {
        pm_Settings::set($this->_prefix . 'shared_files', $value);
    }

    // -- SSH Key management --

    public function getSshKeyDir()
    {
        return pm_Context::getVarDir() . 'ssh-keys/' . $this->_domain->getId();
    }

    public function getSshPrivateKeyPath()
    {
        return $this->getSshKeyDir() . '/id_ed25519';
    }

    public function getSshPublicKeyPath()
    {
        return $this->getSshKeyDir() . '/id_ed25519.pub';
    }

    public function isSshRepo()
    {
        $repo = $this->getGitRepo();
        return (bool) preg_match('#^(git@|ssh://)#', $repo);
    }

    public function getRepoWebUrl()
    {
        $repo = $this->getGitRepo();
        // git@github.com:user/repo.git
        if (preg_match('#^git@([^:]+):(.+?)(?:\.git)?$#', $repo, $m)) {
            return 'https://' . $m[1] . '/' . $m[2];
        }
        // https://github.com/user/repo.git
        if (preg_match('#^https?://(.+?)(?:\.git)?$#', $repo, $m)) {
            return 'https://' . $m[1];
        }
        return '';
    }

    // -- Deployment steps --

    const STEPS = [
        'composer_install' => [
            'label' => 'Install Composer dependencies',
            'description' => 'composer install --no-dev --optimize-autoloader',
            'phase' => 'pre',
            'group' => 'PHP',
            'default' => true,
        ],
        'node_install' => [
            'label' => 'Install Node.js dependencies',
            'description' => 'Auto-detects pnpm / yarn / npm from lock files',
            'phase' => 'pre',
            'group' => 'Node.js',
        ],
        'node_build' => [
            'label' => 'Build frontend assets',
            'description' => 'npm run build (or pnpm/yarn equivalent)',
            'phase' => 'pre',
            'group' => 'Node.js',
        ],
        'migrate' => [
            'label' => 'Run database migrations (Laravel)',
            'description' => 'php artisan migrate --force',
            'phase' => 'post',
            'group' => 'Laravel',
        ],
        'optimize' => [
            'label' => 'Optimize application (Laravel)',
            'description' => 'php artisan optimize — caches config, routes, views, events (Laravel 11+)',
            'phase' => 'post',
            'group' => 'Laravel',
        ],
        'queue_restart' => [
            'label' => 'Restart queue workers (Laravel)',
            'description' => 'php artisan queue:restart — workers pick up new code',
            'phase' => 'post',
            'group' => 'Laravel',
        ],
    ];

    public function isStepEnabled($step)
    {
        $default = self::STEPS[$step]['default'] ?? false;
        return (bool) pm_Settings::get($this->_prefix . 'step_' . $step, $default);
    }

    public function setStepEnabled($step, $value)
    {
        pm_Settings::set($this->_prefix . 'step_' . $step, $value ? '1' : '0');
    }

    public function getEnabledSteps($phase = null)
    {
        $enabled = [];
        foreach (self::STEPS as $key => $info) {
            if ($this->isStepEnabled($key)) {
                if ($phase === null || $info['phase'] === $phase) {
                    $enabled[$key] = $info;
                }
            }
        }
        return $enabled;
    }

    public function save()
    {
        // pm_Settings persists immediately on each set() call
    }

    // -- WWW-Root --

    public function isWwwRootSet()
    {
        return (bool) pm_Settings::get($this->_prefix . 'www_root_set', false);
    }

    public function setWwwRootSet($value)
    {
        pm_Settings::set($this->_prefix . 'www_root_set', $value ? '1' : '0');
    }

    public function delete()
    {
        $keys = [
            'enabled', 'git_repo', 'branch', 'keep_releases',
            'health_check_url', 'health_check_timeout',
            'pre_deploy_script', 'post_deploy_script',
            'current_release', 'last_deploy_time', 'last_deploy_status',
            'webhook_secret', 'shared_dirs', 'shared_files', 'node_pm', 'node_version', 'deploy_mode', 'teams_notify',
            'www_root_set',
        ];
        foreach ($keys as $key) {
            pm_Settings::set($this->_prefix . $key, null);
        }
        foreach (array_keys(self::STEPS) as $step) {
            pm_Settings::set($this->_prefix . 'step_' . $step, null);
        }
    }
}
