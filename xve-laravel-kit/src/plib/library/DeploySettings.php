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

    public function isWebhookForceAllowed()
    {
        return (bool) pm_Settings::get($this->_prefix . 'webhook_allow_force', false);
    }

    public function setWebhookForceAllowed($value)
    {
        pm_Settings::set($this->_prefix . 'webhook_allow_force', $value ? '1' : '0');
    }

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

    // -- PHP version --

    /**
     * Selected PHP version for deploys. 'auto' = detect from the domain's
     * Plesk PHP handler (recommended). An explicit "8.4" pins that version.
     */
    public function getPhpVersion()
    {
        return pm_Settings::get($this->_prefix . 'php_version', 'auto');
    }

    public function setPhpVersion($value)
    {
        pm_Settings::set($this->_prefix . 'php_version', $value);
    }

    /**
     * Bin directory for the explicitly selected PHP version, or '' when set to
     * 'auto' (let the Deployer detect it from the domain's PHP handler).
     */
    public function getPhpBinDir()
    {
        $version = $this->getPhpVersion();
        if ($version === 'auto' || $version === 'system' || empty($version)) {
            return '';
        }
        return '/opt/plesk/php/' . $version . '/bin';
    }

    /**
     * Discover installed Plesk PHP versions from /opt/plesk/php.
     * Returns array like ['8.4' => '8.4', ...] (only versions with a php binary).
     */
    public static function getAvailablePhpVersions()
    {
        $versions = [];
        $baseDir = '/opt/plesk/php';
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
            if (file_exists($baseDir . '/' . $entry . '/bin/php')) {
                $versions[$entry] = $entry;
            }
        }
        uksort($versions, 'version_compare');
        return $versions;
    }

    // -- Shared directories / files --

    const DEFAULT_SHARED_DIRS = "storage\nlogs";
    const DEFAULT_SHARED_FILES = ".env";

    /**
     * Validate a single shared path entry.
     *
     * A valid shared path must be a non-empty relative path that:
     * - is not empty or whitespace-only
     * - is not "." or ".."
     * - contains no null bytes
     * - does not start with "/"  (no absolute paths)
     * - contains no ".." segments (no directory traversal)
     * - contains no "." segments (no current-dir components)
     */
    public static function validateSharedPath($path)
    {
        if (!is_string($path) || trim($path) === '') {
            return false;
        }
        // Reject null bytes before trimming — trim() itself strips chr(0)
        // which would allow a null-byte-prefixed path to slip through.
        if (strpos($path, "\0") !== false) {
            return false;
        }
        $path = trim($path);
        // Reject absolute paths
        if ($path[0] === '/') {
            return false;
        }
        // Reject lone dot or double-dot
        if ($path === '.' || $path === '..') {
            return false;
        }
        // Reject any .. or . segment in the path
        foreach (explode('/', $path) as $segment) {
            if ($segment === '..' || $segment === '.') {
                return false;
            }
        }
        return true;
    }

    public function getSharedDirs()
    {
        $raw = pm_Settings::get($this->_prefix . 'shared_dirs', self::DEFAULT_SHARED_DIRS);
        return array_values(array_filter(array_map('trim', explode("\n", $raw)), [__CLASS__, 'validateSharedPath']));
    }

    public function setSharedDirs($value)
    {
        pm_Settings::set($this->_prefix . 'shared_dirs', $value);
    }

    public function getSharedFiles()
    {
        $raw = pm_Settings::get($this->_prefix . 'shared_files', self::DEFAULT_SHARED_FILES);
        return array_values(array_filter(array_map('trim', explode("\n", $raw)), [__CLASS__, 'validateSharedPath']));
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

    // -- Repo URL allowlist --
    // Only SSH URLs to an admin-configured GitHub org are permitted (security:
    // trusted repos only). The allowed orgs are an extension-level setting so the
    // list is never hard-coded in this (public) source tree.
    const ALLOWED_REPO_HOST = 'github.com';
    const SETTING_ALLOWED_REPO_OWNERS = 'xlk_allowed_repo_owners';

    /**
     * The GitHub orgs/owners allowed as deploy sources, configured by an admin in
     * the extension settings. Stored newline/comma-separated; returns a trimmed
     * list with empties removed. Empty when nothing has been configured yet.
     */
    public static function getAllowedRepoOwners()
    {
        $raw = pm_Settings::get(self::SETTING_ALLOWED_REPO_OWNERS, '');
        $owners = preg_split('/[\s,]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY);
        return array_values(array_unique(array_map('trim', $owners)));
    }

    public static function setAllowedRepoOwners($value)
    {
        pm_Settings::set(self::SETTING_ALLOWED_REPO_OWNERS, trim((string) $value));
    }

    /**
     * Validate a git repository URL against the allowlist.
     * Accepts only scp-style SSH URLs to an allowed owner on the allowed host:
     *   git@github.com:<allowed-owner>/<repo>(.git)
     * Rejects HTTPS, other hosts, other owners, traversal, and malformed input.
     * Fails closed: when no owner has been configured, every URL is rejected.
     */
    public static function validateRepoUrl($url)
    {
        if (!is_string($url)) {
            return false;
        }
        $url = trim($url);
        if ($url === '' || strpos($url, "\0") !== false) {
            return false;
        }
        // scp-style: git@HOST:OWNER/REPO(.git)
        if (!preg_match('#^git@([^:/]+):([^/]+)/([A-Za-z0-9._-]+?)(?:\.git)?$#', $url, $m)) {
            return false;
        }
        $host = $m[1];
        $owner = $m[2];
        $repo = $m[3];
        if ($host !== self::ALLOWED_REPO_HOST) {
            return false;
        }
        $allowedOwners = self::getAllowedRepoOwners();
        if (empty($allowedOwners) || !in_array($owner, $allowedOwners, true)) {
            return false;
        }
        // repo name must not be empty, '.' or '..'
        if ($repo === '' || $repo === '.' || $repo === '..') {
            return false;
        }
        return true;
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
            'webhook_secret', 'webhook_allow_force', 'shared_dirs', 'shared_files', 'node_pm', 'node_version', 'deploy_mode', 'teams_notify',
            'www_root_set',
        ];
        foreach ($keys as $key) {
            pm_Settings::set($this->_prefix . $key, null);
        }
        foreach (array_keys(self::STEPS) as $step) {
            pm_Settings::set($this->_prefix . 'step_' . $step, null);
        }
        // Clean up the non-prefixed deploy lock and banner used by Task/Deploy
        pm_Settings::set('xlk_deploy_lock_' . $this->_domain->getId(), null);
        pm_Settings::set('xlk_deploying', null);
    }
}
