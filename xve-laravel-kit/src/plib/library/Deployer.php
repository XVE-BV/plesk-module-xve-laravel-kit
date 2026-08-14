<?php

/**
 * Atomic deployer with rollback support + Laravel management.
 *
 * Directory structure under the domain's vhost:
 *   /releases/{timestamp}/   - Each deployment snapshot
 *   /shared/                 - Persistent files (logs, uploads, .env)
 *   /current -> releases/X   - Symlink to active release
 *   /.deploy-history.json    - Deployment log
 */
class Modules_XveLaravelKit_Deployer
{
    const HISTORY_FILE = '.deploy-history.json';
    const SBIN_SCRIPT = 'xve-exec.sh';

    /**
     * Regex that a sanitized artisan command (after stripping "php artisan") must match.
     * Allows: letters, digits, colon, hyphen, underscore, dot, equals,
     * spaces, commas, and single/double quotes — the minimal set for valid artisan invocations.
     */
    const ARTISAN_COMMAND_PATTERN = '/^[a-zA-Z0-9:_ \-="\'.,]+$/';

    /**
     * Commands that are blocked and must be run manually via SSH.
     */
    const ARTISAN_BLOCKED_COMMANDS = ['migrate:fresh', 'migrate:reset', 'db:wipe', 'db:seed', 'key:generate'];

    private $_domain;
    private $_settings;
    private $_fileManager;
    private $_basePath;

    public function __construct(pm_Domain $domain, Modules_XveLaravelKit_DeploySettings $settings)
    {
        $this->_domain = $domain;
        $this->_settings = $settings;
        $this->_fileManager = new pm_ServerFileManager();
        $this->_basePath = $this->_getBasePath();
    }

    // ─── Initialize (first-time setup) ────────────────────────

    /**
     * Set up the directory structure and shared files for a new domain.
     * Safe to call multiple times — skips anything that already exists.
     */
    public function initialize($setWwwRoot = true)
    {
        $this->_ensureStructure();

        $user = $this->_getSystemUser();
        $group = 'psaserv';

        // Seed .env from .env.example in git repo, or create empty
        $envPath = $this->_basePath . '/shared/.env';
        if (!$this->_fileManager->fileExists($envPath)) {
            $envExample = $this->_fetchFileFromRepo('.env.example');
            $contents = $envExample ?: '';

            // Auto-fill sensible defaults for this domain
            $domainName = $this->_domain->getDisplayName();
            $appUrl = 'https://' . $domainName;

            // APP_KEY
            if (empty($contents) || preg_match('/^APP_KEY=\s*$/m', $contents)) {
                $key = 'base64:' . base64_encode(random_bytes(32));
                if (preg_match('/^APP_KEY=/m', $contents)) {
                    $contents = preg_replace('/^APP_KEY=.*$/m', 'APP_KEY=' . $key, $contents);
                } elseif (!empty($contents)) {
                    $contents = "APP_KEY={$key}\n" . $contents;
                } else {
                    $contents = "APP_KEY={$key}\n";
                }
            }

            // APP_URL — replace localhost/default with actual domain
            if (preg_match('/^APP_URL=\s*(http:\/\/localhost|http:\/\/127\.0\.0\.1)?\s*$/m', $contents)) {
                $contents = preg_replace('/^APP_URL=.*$/m', 'APP_URL=' . $appUrl, $contents);
            }

            // APP_NAME — set from domain if still default
            if (preg_match('/^APP_NAME=\s*(Laravel)?\s*$/m', $contents)) {
                $name = ucfirst(explode('.', $domainName)[0]);
                $contents = preg_replace('/^APP_NAME=.*$/m', 'APP_NAME=' . $name, $contents);
            }

            // DB_* — detect database from Plesk if available
            $dbInfo = $this->_detectDatabase();
            if ($dbInfo) {
                if (preg_match('/^DB_DATABASE=\s*(laravel|homestead|forge)?\s*$/m', $contents)) {
                    $contents = preg_replace('/^DB_DATABASE=.*$/m', 'DB_DATABASE=' . $dbInfo['name'], $contents);
                }
                if (!empty($dbInfo['login']) && preg_match('/^DB_USERNAME=\s*(root|homestead|forge)?\s*$/m', $contents)) {
                    $contents = preg_replace('/^DB_USERNAME=.*$/m', 'DB_USERNAME=' . $dbInfo['login'], $contents);
                }
                if (preg_match('/^DB_HOST=\s*(127\.0\.0\.1|localhost)?\s*$/m', $contents)) {
                    $contents = preg_replace('/^DB_HOST=.*$/m', 'DB_HOST=localhost', $contents);
                }
            }

            $this->_fileManager->filePutContents($envPath, $contents);
        }

        // Ensure APP_KEY is set (also handles pre-existing .env with empty key)
        $this->ensureAppKey();

        // Recursive ownership + permissions on shared/ so Laravel can write
        $sharedPath = $this->_basePath . '/shared';
        $this->_sbin('chown-r', [$user, $group, $sharedPath]);
        $this->_sbin('fix-shared-perms', [$sharedPath]);

        // Set Plesk document root to current/public (current is a symlink to the active release)
        if ($setWwwRoot) {
            $this->setWwwRoot();
        }

        $this->_fixOwnership();
    }

    /**
     * Set the Plesk document root to current/public.
     */
    public function setWwwRoot()
    {
        $domainName = $this->_domain->getDisplayName();
        $this->_sbin('plesk-site-wwwroot', [$domainName, 'current/public']);
        $this->_settings->setWwwRootSet(true);
    }

    // ─── Teardown (remove everything) ─────────────────────────

    /**
     * Remove all deployment artifacts: releases, shared, current symlink, history.
     * Restores httpdocs if an _original backup exists.
     */
    public function teardown()
    {
        // Reset Plesk document root back to httpdocs
        $domainName = $this->_domain->getDisplayName();
        try {
            $this->_sbin('plesk-site-wwwroot', [$domainName, 'httpdocs']);
        } catch (\Throwable $e) {}

        // Remove current symlink
        $this->_sbin('rm-f', [$this->_basePath . '/current']);
        $this->_sbin('rm-f', [$this->_basePath . '/artisan']);
        $this->_sbin('rm-f', [$this->_basePath . '/' . self::HISTORY_FILE]);

        // Ensure httpdocs exists so Plesk has a valid document root
        $httpdocs = $this->_basePath . '/httpdocs';
        if (!$this->_dirExists($httpdocs)) {
            $this->_sbin('mkdir-p', [$httpdocs]);
            $user = $this->_getSystemUser();
            $this->_sbin('chown', [$user, 'psaserv', $httpdocs]);
        }

        // Archive shared/ before deletion so .env, uploads, and logs can be recovered
        $timestamp = date('Ymd_His');
        $sharedPath = $this->_basePath . '/shared';
        $releasesPath = $this->_basePath . '/releases';

        if ($this->_dirExists($sharedPath)) {
            $sharedArchive = $this->_basePath . '/shared-teardown-' . $timestamp . '.tar.gz';
            try {
                $this->_sbin('tar-czf-shared', [$sharedArchive, $this->_basePath]);
                \pm_Log::info('Teardown: shared/ backed up to ' . $sharedArchive);
            } catch (\Throwable $e) {
                \pm_Log::info('Teardown: could not archive shared/ - ' . $e->getMessage());
            }
        }

        // Remove releases and shared (releases are re-deployable from git, so no backup needed)
        $this->_sbin('rm-rf', [$this->_basePath . '/releases']);
        $this->_sbin('rm-rf', [$this->_basePath . '/shared']);
    }

    // ─── Deploy ────────────────────────────────────────────────

    public function deploy()
    {
        $release = date('Ymd_His');
        $releasePath = $this->_basePath . '/releases/' . $release;
        $previousRelease = $this->_settings->getCurrentRelease();

        try {
            $this->_ensureStructure();
            $this->_gitClone($releasePath);
            $this->_chownRelease($releasePath);
            $this->_linkShared($releasePath);

            $this->_runDeploySteps('pre', $releasePath);
            $this->_runScript($this->_settings->getPreDeployScript(), $releasePath, 'pre-deploy');

            $this->_switchRelease($releasePath);

            $this->_runDeploySteps('post', $releasePath);
            $this->_runScript($this->_settings->getPostDeployScript(), $releasePath, 'post-deploy');

            $this->_healthCheck();

            $this->_settings->setCurrentRelease($release);
            $this->_settings->setLastDeployTime(date('Y-m-d H:i:s'));
            $this->_settings->setLastDeployStatus('success');
            $this->_addHistory($release, 'deploy', 'success');
            $this->_cleanup();

            // Ensure artisan symlink at vhost root for Laravel Toolkit compatibility
            $this->_ensureArtisanSymlink();

            // Create public/storage -> shared/storage/app/public symlink
            $this->_ensureStorageLink($releasePath);

            // Fix ownership on all symlinks/dirs so nginx/PHP-FPM can traverse them
            $this->_fixOwnership();

            return ['success' => true, 'release' => $release];
        } catch (\Throwable $e) {
            $this->_addHistory($release, 'deploy', 'failed');
            $this->_settings->setLastDeployTime(date('Y-m-d H:i:s'));
            $this->_settings->setLastDeployStatus('failed');

            if ($previousRelease) {
                try {
                    $prevPath = $this->_basePath . '/releases/' . $previousRelease;
                    $this->_switchRelease($prevPath);
                } catch (\Throwable $rollbackError) {
                    // Rollback failed too
                }
            }

            return ['success' => false, 'error' => $e->getMessage(), 'release' => $release];
        }
    }

    public function rollback($release)
    {
        if (!preg_match('/^\d{8}_\d{6}$/', $release)) {
            return ['success' => false, 'error' => 'Invalid release name.'];
        }

        $releasePath = $this->_basePath . '/releases/' . $release;

        if (!$this->_dirExists($releasePath)) {
            return ['success' => false, 'error' => 'Release not found: ' . $release];
        }

        try {
            $this->_switchRelease($releasePath);
            $this->_fixOwnership();
            $this->_settings->setCurrentRelease($release);
            $this->_settings->setLastDeployTime(date('Y-m-d H:i:s'));
            $this->_settings->setLastDeployStatus('success');
            $this->_addHistory($release, 'rollback', 'success');
            return ['success' => true];
        } catch (\Throwable $e) {
            $this->_addHistory($release, 'rollback', 'failed');
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function getReleases()
    {
        $releasesDir = $this->_basePath . '/releases';
        if (!$this->_dirExists($releasesDir)) {
            return [];
        }

        try {
            $output = $this->_sbin('ls-1r', [$releasesDir]);
        } catch (\Throwable $e) {
            return [];
        }

        $currentRelease = $this->_settings->getCurrentRelease();
        $statusMap = $this->_getReleaseStatusMap();
        $releases = [];

        foreach (array_filter(explode("\n", trim($output))) as $name) {
            if (!preg_match('/^\d{8}_\d{6}$/', $name)) {
                continue;
            }

            $isCurrent = ($name === $currentRelease);
            $status = 'unknown';
            $commit = null;
            if ($isCurrent) {
                $status = 'current';
            }
            if (isset($statusMap[$name])) {
                if ($status === 'unknown') {
                    $status = $statusMap[$name]['status'];
                }
                $commit = $statusMap[$name]['commit'];
            }

            $date = '';
            if (preg_match('/^(\d{4})(\d{2})(\d{2})_(\d{2})(\d{2})(\d{2})$/', $name, $m)) {
                $date = "$m[1]-$m[2]-$m[3] $m[4]:$m[5]:$m[6]";
            }

            $releases[] = [
                'name' => $name,
                'date' => $date,
                'current' => $isCurrent,
                'status' => $status,
                'commit' => $commit,
            ];
        }

        return $releases;
    }

    public function getHistory()
    {
        $historyFile = $this->_basePath . '/' . self::HISTORY_FILE;
        if (!$this->_fileManager->fileExists($historyFile)) {
            return [];
        }
        $content = $this->_fileManager->fileGetContents($historyFile);
        $history = json_decode($content, true);
        return is_array($history) ? array_reverse($history) : [];
    }

    public function cleanFailedReleases()
    {
        $releases = $this->getReleases();
        $removed = 0;
        foreach ($releases as $release) {
            // Only remove releases explicitly marked as 'failed' in the deploy history.
            // Successful and rollback releases are kept so that _cleanup() / keepReleases
            // can prune them in a controlled way, preserving rollback capability.
            if (!$release['current'] && in_array($release['status'], ['failed', 'unknown'], true)) {
                $path = $this->_basePath . '/releases/' . $release['name'];
                $this->_sbin('rm-rf', [$path]);
                $removed++;
            }
        }
        return $removed;
    }

    // ─── Laravel Management ────────────────────────────────────

    /**
     * Get application info from the current release.
     */
    public function hasCurrentRelease()
    {
        $currentPath = $this->_basePath . '/current';
        return $this->_dirExists($currentPath)
            && $this->_fileManager->fileExists($currentPath . '/artisan');
    }

    public function getAppInfo()
    {
        $info = [
            'laravel_version' => null,
            'php_version' => null,
            'environment' => null,
            'debug' => null,
            'app_name' => null,
            'app_url' => null,
            'db_connection' => null,
            'db_host' => null,
            'db_database' => null,
            'db_username' => null,
            'db_password' => null,
            'app_key' => null,
            'cache_store' => null,
            'queue_connection' => null,
            'mail_mailer' => null,
            'has_env' => false,
            'has_current' => false,
        ];

        $currentPath = $this->_basePath . '/current';
        $info['has_current'] = $this->_dirExists($currentPath);
        if (!$info['has_current']) {
            return $info;
        }

        // Laravel version from composer.lock
        try {
            $lockFile = $currentPath . '/composer.lock';
            if ($this->_fileManager->fileExists($lockFile)) {
                $lockContent = $this->_fileManager->fileGetContents($lockFile);
                $lock = json_decode($lockContent, true);
                if (is_array($lock) && isset($lock['packages'])) {
                    foreach ($lock['packages'] as $pkg) {
                        if ($pkg['name'] === 'laravel/framework') {
                            $info['laravel_version'] = $pkg['version'];
                            break;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {}

        // PHP version
        try {
            $phpBinDir = $this->_getPhpBinDir();
            $phpBin = $phpBinDir ? $phpBinDir . '/php' : 'php';
            $info['php_version'] = trim($this->_sbin('php-version', [$phpBin]));
        } catch (\Throwable $e) {}

        // .env values
        $env = $this->getEnvContents();
        if (!empty($env)) {
            $info['has_env'] = true;
            $parsed = $this->_parseEnv($env);
            $info['environment'] = $parsed['APP_ENV'] ?? null;
            $info['debug'] = $parsed['APP_DEBUG'] ?? null;
            $info['app_name'] = $parsed['APP_NAME'] ?? null;
            $info['app_url'] = $parsed['APP_URL'] ?? null;
            $info['db_connection'] = $parsed['DB_CONNECTION'] ?? null;
            $info['db_host'] = $parsed['DB_HOST'] ?? null;
            $info['db_database'] = $parsed['DB_DATABASE'] ?? null;
            $info['db_username'] = $parsed['DB_USERNAME'] ?? null;
            $info['db_password'] = $parsed['DB_PASSWORD'] ?? null;
            $info['app_key'] = $parsed['APP_KEY'] ?? null;
            $info['cache_store'] = $parsed['CACHE_STORE'] ?? null;
            $info['queue_connection'] = $parsed['QUEUE_CONNECTION'] ?? null;
            $info['mail_mailer'] = $parsed['MAIL_MAILER'] ?? null;
        }

        return $info;
    }

    /**
     * Read the shared .env file contents.
     */
    public function getEnvContents()
    {
        $envPath = $this->_basePath . '/shared/.env';
        try {
            if ($this->_fileManager->fileExists($envPath)) {
                return $this->_fileManager->fileGetContents($envPath);
            }
        } catch (\Throwable $e) {}
        return '';
    }

    // ─── Composer auth.json ─────────────────────────────────────

    public function getComposerAuthPath()
    {
        return $this->_basePath . '/shared/auth.json';
    }

    public function hasComposerAuth()
    {
        try {
            return $this->_fileManager->fileExists($this->getComposerAuthPath());
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function getComposerAuthContents()
    {
        $path = $this->getComposerAuthPath();
        try {
            if ($this->_fileManager->fileExists($path)) {
                return $this->_fileManager->fileGetContents($path);
            }
        } catch (\Throwable $e) {}
        return '';
    }

    public function saveComposerAuth($contents)
    {
        $path = $this->getComposerAuthPath();

        // Validate JSON
        $decoded = json_decode($contents, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Invalid JSON: ' . json_last_error_msg());
        }

        $this->_fileManager->filePutContents($path, $contents);

        $user = $this->_getSystemUser();
        $this->_sbin('chown', [$user, 'psaserv', $path]);
        $this->_sbin('chmod', ['600', $path]);
    }

    public function deleteComposerAuth()
    {
        $path = $this->getComposerAuthPath();
        if ($this->_fileManager->fileExists($path)) {
            $this->_sbin('rm-f', [$path]);
        }
    }

    /**
     * Validate .env contents before saving.
     *
     * Returns an array of error/warning messages. Empty array = valid.
     * Each entry: ['level' => 'error'|'warning', 'message' => '...']
     */
    public function validateEnvContents($contents)
    {
        $issues = [];
        $lines = explode("\n", $contents);
        $keys = [];

        foreach ($lines as $lineNum => $line) {
            $trimmed = trim($line);
            $num = $lineNum + 1;

            // Skip empty lines and comments
            if (empty($trimmed) || $trimmed[0] === '#') {
                continue;
            }

            // Must contain =
            if (strpos($trimmed, '=') === false) {
                $issues[] = [
                    'level' => 'error',
                    'message' => "Line {$num}: Invalid syntax — missing '=' sign: " . mb_substr($trimmed, 0, 60),
                ];
                continue;
            }

            $pos = strpos($trimmed, '=');
            $key = substr($trimmed, 0, $pos);
            $value = substr($trimmed, $pos + 1);

            // Key must be a valid env variable name
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
                $issues[] = [
                    'level' => 'error',
                    'message' => "Line {$num}: Invalid key name '{$key}' — only letters, digits, and underscores allowed.",
                ];
            }

            // Check for duplicate keys
            if (isset($keys[$key])) {
                $issues[] = [
                    'level' => 'warning',
                    'message' => "Line {$num}: Duplicate key '{$key}' (first seen on line {$keys[$key]}).",
                ];
            } else {
                $keys[$key] = $num;
            }

            // Check for unbalanced quotes in value
            $quoteCount = substr_count($value, '"') - substr_count($value, '\\"');
            if ($quoteCount % 2 !== 0) {
                $issues[] = [
                    'level' => 'error',
                    'message' => "Line {$num}: Unbalanced double quotes for key '{$key}'.",
                ];
            }
            $singleQuotes = substr_count($value, "'");
            if ($singleQuotes % 2 !== 0) {
                $issues[] = [
                    'level' => 'error',
                    'message' => "Line {$num}: Unbalanced single quotes for key '{$key}'.",
                ];
            }
        }

        // Warn about missing essential Laravel keys
        $recommended = ['APP_KEY', 'APP_ENV', 'APP_URL'];
        foreach ($recommended as $rk) {
            if (!isset($keys[$rk])) {
                $issues[] = [
                    'level' => 'warning',
                    'message' => "Missing recommended key: {$rk}",
                ];
            }
        }

        // APP_KEY must not be empty if present
        if (isset($keys[$rk = 'APP_KEY'])) {
            $appKeyLine = $lines[$keys[$rk] - 1];
            $appKeyValue = trim(substr($appKeyLine, strpos($appKeyLine, '=') + 1));
            if (empty($appKeyValue)) {
                $issues[] = [
                    'level' => 'warning',
                    'message' => "APP_KEY is empty. Run 'php artisan key:generate' after saving.",
                ];
            }
        }

        return $issues;
    }

    /**
     * Write the shared .env file.
     */
    public function saveEnvContents($contents)
    {
        $envPath = $this->_basePath . '/shared/.env';

        // Backup existing .env
        try {
            if ($this->_fileManager->fileExists($envPath)) {
                $backup = $this->_basePath . '/shared/.env.backup.' . date('Ymd_His');
                $this->_sbin('cp', [$envPath, $backup]);
            }
        } catch (\Throwable $e) {}

        // Keep only the 10 most recent .env backups; delete older ones
        try {
            $sharedDir = $this->_basePath . '/shared';
            $backups = glob($sharedDir . '/.env.backup.*');
            if (is_array($backups) && count($backups) > 10) {
                sort($backups);
                $excess = array_slice($backups, 0, count($backups) - 10);
                foreach ($excess as $old) {
                    $this->_sbin('rm-f', [$old]);
                }
            }
        } catch (\Throwable $e) {}

        $this->_fileManager->filePutContents($envPath, $contents);

        // Chown to system user
        $user = $this->_getSystemUser();
        $this->_sbin('chown', [$user, 'psaserv', $envPath]);
    }

    /**
     * Get .env.example contents from the current release.
     */
    public function getEnvExampleContents()
    {
        $examplePath = $this->_basePath . '/current/.env.example';
        try {
            if ($this->_fileManager->fileExists($examplePath)) {
                return $this->_fileManager->fileGetContents($examplePath);
            }
        } catch (\Throwable $e) {}
        return '';
    }

    /**
     * Generate APP_KEY if the .env has an empty or missing APP_KEY.
     * Safe to call multiple times — skips if key already set.
     */
    public function ensureAppKey()
    {
        $envPath = $this->_basePath . '/shared/.env';
        try {
            $contents = $this->_fileManager->fileExists($envPath)
                ? $this->_fileManager->fileGetContents($envPath)
                : '';
        } catch (\Throwable $e) {
            return;
        }

        $parsed = $this->_parseEnv($contents);
        $appKey = $parsed['APP_KEY'] ?? '';

        if (!empty($appKey)) {
            return;
        }

        $key = 'base64:' . base64_encode(random_bytes(32));

        if (preg_match('/^APP_KEY=/m', $contents)) {
            $contents = preg_replace('/^APP_KEY=.*$/m', 'APP_KEY=' . $key, $contents);
        } elseif (!empty($contents)) {
            $contents = "APP_KEY={$key}\n" . $contents;
        } else {
            $contents = "APP_KEY={$key}\n";
        }

        $this->_fileManager->filePutContents($envPath, $contents);
    }

    /**
     * Run an artisan command in the current release as the system user.
     */
    public function runArtisan($command)
    {
        $currentPath = $this->_basePath . '/current';
        if (!$this->_dirExists($currentPath)) {
            return ['success' => false, 'output' => 'No current release found.'];
        }

        // Sanitize: strip leading "php artisan" if user typed it
        $command = preg_replace('/^\s*(php\s+)?artisan\s+/', '', $command);

        // Allowlist: only permit characters that are safe in artisan commands
        if (!preg_match(self::ARTISAN_COMMAND_PATTERN, $command)) {
            return ['success' => false, 'output' => 'Command contains invalid characters. Only alphanumeric characters, colons, hyphens, underscores, equals signs, spaces, quotes, dots, and commas are allowed.'];
        }

        // Block dangerous commands (secondary safety net)
        $cmdBase = explode(' ', trim($command))[0];
        if (in_array($cmdBase, self::ARTISAN_BLOCKED_COMMANDS, true)) {
            return ['success' => false, 'output' => "Command '$cmdBase' is blocked for safety. Run it manually via SSH if needed."];
        }

        $phpBinDir = $this->_getPhpBinDir();
        $pathExport = $phpBinDir ? 'export PATH="' . $phpBinDir . ':$PATH"' . "\n" : '';

        try {
            $scriptPath = $currentPath . '/.xve-artisan-' . uniqid() . '.sh';
            $this->_fileManager->filePutContents(
                $scriptPath,
                "#!/bin/bash\nset -euo pipefail\n" . $pathExport . 'cd ' . escapeshellarg($currentPath) . ' && php artisan ' . $command . "\n"
            );
            $this->_sbin('chmod', ['+x', $scriptPath]);
            $output = $this->_sbin('run-script-as-user', [$this->_getSystemUser(), $scriptPath]);
            $this->_sbin('rm-f', [$scriptPath]);
            return ['success' => true, 'output' => $output];
        } catch (\Throwable $e) {
            return ['success' => false, 'output' => $e->getMessage()];
        }
    }

    /**
     * Pick the active Laravel log file out of a logs-directory listing.
     *
     * Static and side-effect free so the selection rules stay unit-testable
     * without Plesk's filesystem layer.
     *
     * @param  string $listing Output of `ls -1r` on the logs directory.
     * @return string|null Chosen filename, or null when nothing matches.
     */
    public static function selectActiveLogFile($listing)
    {
        $names = array_filter(array_map('trim', explode("\n", trim((string) $listing))));

        // The `single` channel's file wins when present, regardless of where
        // the listing happens to place it.
        if (in_array('laravel.log', $names, true)) {
            return 'laravel.log';
        }

        // The pattern keeps unrelated files in this directory
        // (exchange-rate.log, stock-load.log, ...) out of the picture.
        $newest = null;
        foreach ($names as $name) {
            if (!preg_match('/^laravel-\d{4}-\d{2}-\d{2}\.log$/', $name)) {
                continue;
            }

            // The dated names are zero-padded ISO, so a plain string
            // comparison finds the newest day. Compared explicitly rather
            // than taking the listing's first match, so the result cannot
            // depend on how `ls` happened to sort.
            if ($newest === null || strcmp($name, $newest) > 0) {
                $newest = $name;
            }
        }

        return $newest;
    }

    /**
     * Resolve the Laravel log file currently being written.
     *
     * The `single` channel writes shared/storage/logs/laravel.log, but the
     * `daily` channel writes laravel-Y-m-d.log and never creates laravel.log
     * at all — so hardcoding the single-file name made this tab report
     * "Log file is empty or does not exist yet" permanently for every app on
     * the daily channel, no matter how much it was logging.
     *
     * @return string|null Absolute path, or null when no Laravel log exists.
     */
    private function _resolveLogPath()
    {
        $logsDir = $this->_basePath . '/shared/storage/logs';

        try {
            // `ls` is silenced on a missing directory, so this doubles as the
            // existence check.
            $listing = $this->_sbin('ls-1r', [$logsDir]);
        } catch (\Throwable $e) {
            return null;
        }

        $name = self::selectActiveLogFile($listing);

        return $name === null ? null : $logsDir . '/' . $name;
    }

    /**
     * Path of the active Laravel log relative to the vhost root, for display.
     */
    public function getLogRelativePath()
    {
        $logPath = $this->_resolveLogPath();
        if ($logPath === null) {
            return 'shared/storage/logs/laravel.log';
        }

        return ltrim(substr($logPath, strlen($this->_basePath)), '/');
    }

    /**
     * Read the active Laravel log file (from shared/storage/logs/).
     */
    public function getLogContents($lines = 200)
    {
        $logPath = $this->_resolveLogPath();
        if ($logPath === null) {
            return '';
        }

        try {
            $output = $this->_sbin('tail-n', [(string)(int)$lines, $logPath]);
            return $output;
        } catch (\Throwable $e) {
            return 'Error reading log: ' . $e->getMessage();
        }
    }

    /**
     * Clear the active Laravel log file.
     *
     * On the daily channel this truncates today's file only; older dated
     * files are left alone so history is not destroyed by a single click.
     */
    public function clearLog()
    {
        $logPath = $this->_resolveLogPath();
        if ($logPath === null) {
            return true;
        }

        try {
            $this->_sbin('truncate0', [$logPath]);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Check if the app is in maintenance mode.
     */
    public function isInMaintenanceMode()
    {
        $downFile = $this->_basePath . '/current/storage/framework/down';
        try {
            return $this->_fileManager->fileExists($downFile);
        } catch (\Throwable $e) {
            return false;
        }
    }

    // ─── Server Prerequisites ──────────────────────────────────

    public function checkPrerequisites()
    {
        $checks = [];
        $user = $this->_getSystemUser();
        $pathDirs = [];
        $nodeBinDir = $this->_settings->getNodeBinDir();
        if (!empty($nodeBinDir)) {
            $pathDirs[] = $nodeBinDir;
        }
        $phpBinDir = $this->_getPhpBinDir();
        if (!empty($phpBinDir)) {
            $pathDirs[] = $phpBinDir;
        }
        $pathPrefix = !empty($pathDirs) ? 'export PATH="' . implode(':', $pathDirs) . ':$PATH" && ' : '';

        $checks['git'] = $this->_checkGitTool();
        $checks['php'] = $this->_checkToolAsUser($user, $pathPrefix . 'php --version | head -1');
        $checks['composer'] = $this->_checkToolAsUser($user, $pathPrefix . 'composer --version 2>&1 | head -1');
        $checks['node'] = $this->_checkToolAsUser($user, $pathPrefix . 'node --version 2>&1');
        $checks['npm'] = $this->_checkToolAsUser($user, $pathPrefix . 'npm --version 2>&1');
        $checks['pnpm'] = $this->_checkToolAsUser($user, $pathPrefix . 'pnpm --version 2>&1');
        $checks['yarn'] = $this->_checkToolAsUser($user, $pathPrefix . 'yarn --version 2>&1');

        $sshKeyExists = false;
        try {
            $sshKeyExists = $this->_fileManager->fileExists($this->_settings->getSshPrivateKeyPath());
        } catch (\Throwable $e) {}
        $checks['ssh_key'] = [
            'name' => 'SSH Deploy Key',
            'ok' => $sshKeyExists,
            'version' => $sshKeyExists ? 'Present' : 'Not generated',
            'required' => true,
        ];

        $basePathOk = false;
        try {
            $result = $this->_sbin('test-dir-writable', [$this->_basePath]);
            $basePathOk = trim($result) === 'yes';
        } catch (\Throwable $e) {}
        $checks['base_path'] = [
            'name' => 'Vhost directory',
            'ok' => $basePathOk,
            'version' => $basePathOk ? $this->_basePath : 'Not accessible',
            'required' => true,
        ];

        $enabledSteps = $this->_settings->getEnabledSteps();
        $stepKeys = array_keys($enabledSteps);
        $checks['git']['required'] = true;
        $checks['php']['required'] = true;
        $checks['composer']['required'] = in_array('composer_install', $stepKeys);
        $nodeRequired = in_array('node_install', $stepKeys) || in_array('node_build', $stepKeys);
        $checks['node']['required'] = $nodeRequired;
        $configuredPm = $this->_settings->getNodePackageManager();
        $checks['npm']['required'] = $nodeRequired && ($configuredPm === 'npm' || ($configuredPm === 'auto' && !$checks['pnpm']['ok'] && !$checks['yarn']['ok']));
        $checks['pnpm']['required'] = $nodeRequired && $configuredPm === 'pnpm';
        $checks['yarn']['required'] = $nodeRequired && $configuredPm === 'yarn';

        return $checks;
    }

    // ─── Internal: Deploy Helpers ──────────────────────────────

    private function _getBasePath()
    {
        return rtrim($this->_domain->getHomePath(), '/');
    }

    /**
     * Detect database for this domain from Plesk's internal DB.
     * Returns ['name' => ..., 'login' => ...] or null.
     */
    private function _detectDatabase()
    {
        try {
            $domainId = $this->_domain->getId();
            $output = $this->_sbin('detect-db', [(string) (int) $domainId]);
            $output = trim($output);
            if (empty($output)) {
                return null;
            }
            // Output is tab-separated: name\tlogin
            $lines = explode("\n", $output);
            // Skip header row if present
            $dataLine = count($lines) > 1 ? $lines[1] : $lines[0];
            $parts = preg_split('/\t+/', trim($dataLine));
            if (count($parts) >= 1 && !empty($parts[0]) && $parts[0] !== 'name') {
                return [
                    'name' => $parts[0],
                    'login' => $parts[1] ?? '',
                ];
            }
        } catch (\Throwable $e) {}

        return null;
    }

    private function _ensureStructure()
    {
        $paths = [
            $this->_basePath . '/releases',
            $this->_basePath . '/shared',
        ];

        foreach ($this->_settings->getSharedDirs() as $dir) {
            $sharedDir = $this->_basePath . '/shared/' . $dir;
            $paths[] = $sharedDir;
            if ($dir === 'storage') {
                $paths[] = $sharedDir . '/app/public';
                $paths[] = $sharedDir . '/framework/cache/data';
                $paths[] = $sharedDir . '/framework/sessions';
                $paths[] = $sharedDir . '/framework/testing';
                $paths[] = $sharedDir . '/framework/views';
                $paths[] = $sharedDir . '/logs';
            }
        }

        $user = $this->_getSystemUser();

        foreach ($paths as $path) {
            if (!$this->_fileManager->fileExists($path)) {
                $this->_sbin('mkdir-p', [$path]);
                $this->_sbin('chown-r', [$user, 'psaserv', $path]);
            }
        }
    }

    public function listRemoteBranches()
    {
        $repo = $this->_settings->getGitRepo();
        if (empty($repo)) {
            return [];
        }

        if (!Modules_XveLaravelKit_DeploySettings::validateRepoUrl($repo)) {
            return [];
        }

        $key = $this->_settings->getSshPrivateKeyPath();
        $knownHosts = Modules_XveLaravelKit_SshKey::ensureKnownHosts($this->_settings);
        $output = $this->_sbin('git-ls-remote', [$key, $knownHosts, $repo]);

        $branches = [];
        foreach (explode("\n", trim($output)) as $line) {
            if (preg_match('#refs/heads/(.+)$#', $line, $m)) {
                $branches[] = $m[1];
            }
        }
        sort($branches);
        return $branches;
    }

    /**
     * Fetch a single file from the git repo without a full clone.
     * Returns file contents or empty string on failure.
     */
    private function _fetchFileFromRepo($filePath)
    {
        $repo = $this->_settings->getGitRepo();
        $branch = $this->_settings->getBranch();
        if (empty($repo)) {
            return '';
        }

        if (!Modules_XveLaravelKit_DeploySettings::validateRepoUrl($repo)) {
            return '';
        }

        $key = $this->_settings->getSshPrivateKeyPath();
        $knownHosts = Modules_XveLaravelKit_SshKey::ensureKnownHosts($this->_settings);

        try {
            return $this->_sbin('git-archive-file', [$key, $knownHosts, $repo, $branch, $filePath]);
        } catch (\Throwable $e) {
            // git archive may not be supported (e.g. GitHub), try shallow clone fallback
            $tmpDir = '/tmp/xlk-init-' . uniqid();
            try {
                $quiet = ($this->_settings->getDeployMode() === 'quiet') ? '1' : '0';
                $this->_sbin('git-clone-file', [$key, $knownHosts, $quiet, $branch, $repo, $tmpDir, $filePath]);
                $contents = $this->_fileManager->fileGetContents($tmpDir . '/' . $filePath);
                $this->_sbin('rm-rf', [$tmpDir]);
                return $contents;
            } catch (\Throwable $e2) {
                // Cleanup and return empty; file may not exist in repo
                try { $this->_sbin('rm-rf', [$tmpDir]); } catch (\Throwable $e3) {}
                return '';
            }
        }
    }

    private function _gitClone($releasePath, $branchOverride = null)
    {
        $repo = $this->_settings->getGitRepo();
        $branch = $branchOverride ?: $this->_settings->getBranch();

        if (!Modules_XveLaravelKit_DeploySettings::validateRepoUrl($repo)) {
            throw new pm_Exception('Refusing to deploy: repository URL is not on the allowlist (a configured GitHub org over SSH only): ' . $repo);
        }

        $key = $this->_settings->getSshPrivateKeyPath();
        $knownHosts = Modules_XveLaravelKit_SshKey::ensureKnownHosts($this->_settings);
        $quiet = ($this->_settings->getDeployMode() === 'quiet') ? '1' : '0';

        $output = $this->_sbin('git-clone', [$key, $knownHosts, $quiet, $branch, $repo, $releasePath]);

        if (!$this->_dirExists($releasePath . '/.git')) {
            throw new pm_Exception('Git clone failed: ' . $output);
        }

        // Capture commit info before removing .git
        $commitHash = trim($this->_sbin('git-rev-parse', [$releasePath]));
        $commitMsg = trim($this->_sbin('git-log', [$releasePath, '%s']));
        $commitAuthor = trim($this->_sbin('git-log', [$releasePath, '%an']));

        $this->_sbin('rm-rf', [$releasePath . '/.git']);

        return [
            'hash' => $commitHash,
            'message' => $commitMsg,
            'author' => $commitAuthor,
            'branch' => $branch,
        ];
    }

    private function _linkShared($releasePath)
    {
        $user = $this->_getSystemUser();

        foreach ($this->_settings->getSharedDirs() as $dir) {
            $target = $releasePath . '/' . $dir;
            $shared = $this->_basePath . '/shared/' . $dir;
            $parentDir = dirname($target);
            if ($parentDir !== $releasePath) {
                $this->_sbin('mkdir-p', [$parentDir]);
            }
            if (!$this->_fileManager->fileExists($shared)) {
                $this->_sbin('mkdir-p', [$shared]);
                $this->_sbin('chown-r', [$user, 'psaserv', $shared]);
            }
            $this->_sbin('rm-rf', [$target]);
            $this->_sbin('ln-sfn', [$shared, $target]);
        }

        foreach ($this->_settings->getSharedFiles() as $file) {
            $target = $releasePath . '/' . $file;
            $shared = $this->_basePath . '/shared/' . $file;
            $sharedParentDir = dirname($shared);
            if ($this->_fileManager->fileExists($shared)) {
                $parentDir = dirname($target);
                if ($parentDir !== $releasePath) {
                    $this->_sbin('mkdir-p', [$parentDir]);
                }
                $this->_sbin('rm-f', [$target]);
                $this->_sbin('ln-sfn', [$shared, $target]);
            } elseif (!$this->_fileManager->fileExists($sharedParentDir)) {
                $this->_sbin('mkdir-p', [$sharedParentDir]);
                $this->_sbin('chown-r', [$user, 'psaserv', $sharedParentDir]);
            }
        }

        // Auto-link auth.json for Composer private package authentication (e.g. Backpack)
        $authJson = $this->_basePath . '/shared/auth.json';
        if ($this->_fileManager->fileExists($authJson)) {
            $target = $releasePath . '/auth.json';
            $this->_sbin('rm-f', [$target]);
            $this->_sbin('ln-sfn', [$authJson, $target]);
        }
    }

    private function _switchRelease($releasePath)
    {
        $currentLink = $this->_basePath . '/current';
        $tempLink = $this->_basePath . '/current_tmp_' . getmypid();

        // If 'current' is a real directory (e.g. created by Plesk when setting www-root),
        // move it to a timestamped backup instead of deleting it outright —
        // mv can't atomically replace a directory with a symlink.
        if (is_dir($currentLink) && !is_link($currentLink)) {
            $backupName = 'current-backup-' . date('Ymd_His');
            $backupPath = $this->_basePath . '/' . $backupName;
            \pm_Log::warn(
                "switchRelease: 'current' is a real directory, moving to backup: {$backupPath}"
            );
            $this->_sbin('mv', [$currentLink, $backupPath]);

            // Keep only the last 2 current-backup-* directories to avoid unbounded growth
            $backupList = glob($this->_basePath . '/current-backup-*', GLOB_ONLYDIR);
            if (is_array($backupList)) {
                sort($backupList);
                $toDelete = array_slice($backupList, 0, max(0, count($backupList) - 2));
                foreach ($toDelete as $old) {
                    $this->_sbin('rm-rf', [$old]);
                }
            }
        }

        // Atomic symlink switch: create temp link, then rename over current
        $this->_sbin('ln-sfn', [$releasePath, $tempLink]);
        $this->_sbin('mv-tf', [$tempLink, $currentLink]);

        // Fix symlink ownership — nginx's disable_symlinks if_not_owner
        // requires the symlink itself to be owned by the domain user
        $user = $this->_getSystemUser();
        $this->_sbin('chown-h', [$user, 'psaserv', $currentLink]);
    }

    private function _ensureArtisanSymlink()
    {
        $artisanLink = $this->_basePath . '/artisan';
        $target = 'current/artisan';
        if ($this->_fileManager->fileExists($this->_basePath . '/current/artisan')) {
            $this->_sbin('ln-sfn', [$target, $artisanLink]);
        }
    }

    private function _ensureStorageLink($releasePath)
    {
        $publicStorage = $releasePath . '/public/storage';
        $target = $this->_basePath . '/shared/storage/app/public';
        if ($this->_dirExists($target) && !$this->_fileManager->fileExists($publicStorage)) {
            $this->_sbin('ln-sfn', [$target, $publicStorage]);
            // chown the symlink itself; nginx disable_symlinks if_not_owner
            // requires the symlink owner to match the target owner
            $user = $this->_getSystemUser();
            $this->_sbin('chown-h', [$user, 'psaserv', $publicStorage]);
        }
    }

    private function _healthCheck()
    {
        $url = $this->_settings->getHealthCheckUrl();
        if (empty($url)) {
            return;
        }

        $timeout = $this->_settings->getHealthCheckTimeout();

        $autoHttps = false;
        if (strpos($url, '/') === 0) {
            // Default to https:// for relative URLs — production sites typically enforce HTTPS.
            $url = 'https://' . $this->_domain->getDisplayName() . $url;
            $autoHttps = true;
        }

        $parsedUrl = parse_url($url);
        $domainName = $this->_domain->getDisplayName();
        if (isset($parsedUrl['host']) && $parsedUrl['host'] !== $domainName) {
            throw new pm_Exception('Health check URL must be on the same domain.');
        }

        $httpCode = trim($this->_sbin('curl-health', [(string)(int)$timeout, $url]));
        $code = (int) $httpCode;

        if (($code < 200 || $code >= 400) && $autoHttps) {
            $url = str_replace('https://', 'http://', $url);
            $httpCode = trim($this->_sbin('curl-health', [(string)(int)$timeout, $url]));
            $code = (int) $httpCode;
        }

        if ($code < 200 || $code >= 400) {
            throw new pm_Exception(
                sprintf('Health check failed: HTTP %s from %s', $httpCode, $url)
            );
        }
    }

    private function _runScript($script, $releasePath, $phase)
    {
        if (empty($script)) {
            return;
        }

        $pathDirs = [];
        $nodeBinDir = $this->_settings->getNodeBinDir();
        if (!empty($nodeBinDir)) {
            $pathDirs[] = $nodeBinDir;
        }
        $phpBinDir = $this->_getPhpBinDir();
        if (!empty($phpBinDir)) {
            $pathDirs[] = $phpBinDir;
        }
        $pathExport = !empty($pathDirs) ? 'export PATH="' . implode(':', $pathDirs) . ':$PATH"' . "\n" : '';

        $scriptPath = $releasePath . '/.xve-' . $phase . '.sh';
        $this->_fileManager->filePutContents($scriptPath,
            "#!/bin/bash\nset -euo pipefail\n" . $pathExport . "cd " . escapeshellarg($releasePath) . "\n" . $script
        );
        $this->_sbin('chmod', ['+x', $scriptPath]);

        $output = $this->_sbin('run-script-as-user', [$this->_getSystemUser(), $scriptPath]);

        $this->_sbin('rm-f', [$scriptPath]);

        return $output;
    }

    private function _runDeploySteps($phase, $releasePath)
    {
        $steps = $this->_settings->getEnabledSteps($phase);
        $commands = [];
        $mode = $this->_settings->getDeployMode();
        $q = ($mode === 'quiet');

        foreach (array_keys($steps) as $step) {
            switch ($step) {
                case 'composer_install':
                    $cmd = 'composer install --no-dev --no-interaction --optimize-autoloader --prefer-dist';
                    if ($q) $cmd .= ' --quiet';
                    $commands[] = $cmd . ' 2>&1';
                    break;
                case 'node_install':
                    $pm = $this->_detectNodePackageManager($releasePath);
                    if ($pm === 'pnpm') {
                        $cmd = 'pnpm install --frozen-lockfile';
                        if ($q) $cmd .= ' --silent';
                    } elseif ($pm === 'yarn') {
                        $cmd = 'yarn install --frozen-lockfile';
                        if ($q) $cmd .= ' --silent';
                    } else {
                        $cmd = 'npm ci';
                        if ($q) $cmd .= ' --silent';
                    }
                    $commands[] = $cmd . ' 2>&1';
                    break;
                case 'node_build':
                    $pm = $this->_detectNodePackageManager($releasePath);
                    $packageJsonPath = $releasePath . '/package.json';
                    if ($this->_fileManager->fileExists($packageJsonPath)) {
                        $pkgJson = json_decode($this->_fileManager->fileGetContents($packageJsonPath), true);
                        if (empty($pkgJson['scripts']['build'])) {
                            throw new \RuntimeException(
                                'The "Build frontend assets" deploy step is enabled but package.json has no "build" script. ' .
                                'Either add a build script to package.json or disable this step in the deploy settings.'
                            );
                        }
                    }
                    $cmd = $pm . ' run build';
                    if ($q && $pm === 'npm') $cmd .= ' --silent';
                    $commands[] = $cmd . ' 2>&1';
                    break;
                case 'migrate':
                    $cmd = 'php artisan migrate --force';
                    if ($q) $cmd .= ' --quiet';
                    $commands[] = $cmd . ' 2>&1';
                    break;
                case 'optimize':
                    $cmd = 'php artisan optimize';
                    if ($q) $cmd .= ' --quiet';
                    $commands[] = $cmd . ' 2>&1';
                    break;
                case 'queue_restart':
                    $cmd = 'php artisan queue:restart';
                    if ($q) $cmd .= ' --quiet';
                    $commands[] = $cmd . ' 2>&1';
                    break;
            }
        }

        if (empty($commands)) {
            return;
        }

        $script = implode("\n", $commands);
        $this->_runScript($script, $releasePath, $phase . '-steps');
    }

    private function _detectNodePackageManager($releasePath)
    {
        $configured = $this->_settings->getNodePackageManager();
        if ($configured !== 'auto') {
            return $configured;
        }
        if ($this->_fileManager->fileExists($releasePath . '/pnpm-lock.yaml')) {
            return 'pnpm';
        }
        if ($this->_fileManager->fileExists($releasePath . '/yarn.lock')) {
            return 'yarn';
        }
        return 'npm';
    }

    private function _cleanup()
    {
        $keepReleases = $this->_settings->getKeepReleases();
        $releases = $this->getReleases();

        if (count($releases) <= $keepReleases) {
            return;
        }

        $toRemove = array_slice($releases, $keepReleases);
        foreach ($toRemove as $release) {
            if ($release['current']) {
                continue;
            }
            $path = $this->_basePath . '/releases/' . $release['name'];
            $this->_sbin('rm-rf', [$path]);
        }
    }

    private function _addHistory($release, $action, $status, $commit = null)
    {
        $historyFile = $this->_basePath . '/' . self::HISTORY_FILE;
        $history = [];

        if ($this->_fileManager->fileExists($historyFile)) {
            $content = $this->_fileManager->fileGetContents($historyFile);
            $decoded = json_decode($content, true);
            if (is_array($decoded)) {
                $history = $decoded;
            }
        }

        $entry = [
            'release' => $release,
            'action' => $action,
            'status' => $status,
            'timestamp' => date('Y-m-d H:i:s'),
            'user' => $this->_getSessionUser(),
        ];

        if ($commit) {
            $entry['commit'] = $commit;
        }

        $history[] = $entry;

        if (count($history) > 100) {
            $history = array_slice($history, -100);
        }

        $this->_fileManager->filePutContents($historyFile, json_encode($history, JSON_PRETTY_PRINT));
    }

    private function _chownRelease($releasePath)
    {
        $user = $this->_getSystemUser();
        // Recursively chown the release directory; it's a fresh clone so this is safe and necessary.
        $this->_sbin('chown-r', [$user, 'psaserv', $releasePath]);
        // Only chown the shared/ directory itself (non-recursive). The contents are
        // persistent across deploys and already have correct ownership, so running
        // chown -R on every deploy is unnecessarily slow on large upload/storage trees.
        $sharedPath = $this->_basePath . '/shared';
        $this->_sbin('chown', [$user, 'psaserv', $sharedPath]);
    }

    /**
     * Fix ownership on all top-level symlinks, directories, and files in the vhost root.
     *
     * Plesk nginx uses `disable_symlinks if_not_owner` which means symlink owner
     * must match target owner. Since sbin runs as root, all symlinks/dirs created
     * by _switchRelease, _ensureArtisanSymlink, _ensureStorageLink, _ensureStructure
     * are root-owned and cause 403. This fixes them in one pass.
     */
    private function _fixOwnership()
    {
        $user = $this->_getSystemUser();
        $group = 'psaserv';

        // chown -h on the basepath itself fixes symlinks without following them,
        // and also fixes regular files/dirs. Non-recursive: releases are chowned individually.
        $this->_sbin('fix-vhost-ownership', [$user, $group, $this->_basePath]);
    }

    private function _getReleaseStatusMap()
    {
        $history = $this->_getRawHistory();
        $map = [];
        foreach ($history as $entry) {
            if (isset($entry['release'], $entry['status'])) {
                $map[$entry['release']] = [
                    'status' => $entry['status'],
                    'commit' => isset($entry['commit']) ? $entry['commit'] : null,
                ];
            }
        }
        return $map;
    }

    private function _getRawHistory()
    {
        $historyFile = $this->_basePath . '/' . self::HISTORY_FILE;
        if ($this->_fileManager->fileExists($historyFile)) {
            $content = $this->_fileManager->fileGetContents($historyFile);
            $decoded = json_decode($content, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return [];
    }

    // ─── Internal: System Helpers ──────────────────────────────

    private function _getSystemUser()
    {
        return $this->_domain->getSysUserLogin();
    }

    private function _getSessionUser()
    {
        try {
            return pm_Session::getClient()->getProperty('login');
        } catch (\Throwable $e) {
            return 'system';
        }
    }

    private function _getPhpBinDir()
    {
        // 1. Explicit per-domain override (Settings -> "PHP Version"). Authoritative.
        try {
            $explicit = $this->_settings->getPhpBinDir();
            if (!empty($explicit)) {
                if ($this->_dirExists($explicit)) {
                    return $explicit;
                }
                \pm_Log::warn('xve-laravel-kit: configured PHP bin dir ' . $explicit
                    . ' does not exist; falling back to auto-detection.');
            }
        } catch (\Throwable $e) {}

        // 2. Detect from the domain's Plesk PHP handler (e.g. plesk-php84-fpm -> 8.4).
        $handler = '';
        try {
            $handler = (string) $this->_domain->getPhpHandlerId();
        } catch (\Throwable $e) {}

        if ($handler !== '' && preg_match('/php-?(\d)\.?(\d)/i', $handler, $m)) {
            $version = $m[1] . '.' . $m[2];
            $dir = '/opt/plesk/php/' . $version . '/bin';
            if ($this->_dirExists($dir)) {
                return $dir;
            }
            \pm_Log::warn('xve-laravel-kit: PHP handler "' . $handler . '" resolved to '
                . $version . ' but ' . $dir . ' is missing.');
        } else {
            \pm_Log::warn('xve-laravel-kit: could not determine PHP version from handler id "'
                . $handler . '".');
        }

        // 3. Last resort: newest installed PHP. This can pick a version the app does
        //    not support (e.g. a freshly installed 8.5), so make it visible in the log.
        try {
            $dir = trim($this->_sbin('php-bindir-latest', []));
            if ($dir !== '') {
                \pm_Log::warn('xve-laravel-kit: falling back to newest installed PHP (' . $dir
                    . '). Set an explicit PHP version in the deploy settings if this is wrong.');
                return $dir;
            }
        } catch (\Throwable $e) {}

        return '';
    }

    private function _dirExists($path)
    {
        try {
            $result = $this->_sbin('test-dir', [$path]);
            return trim($result) === 'yes';
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function _parseEnv($content)
    {
        $parsed = [];
        foreach (explode("\n", $content) as $line) {
            $line = trim($line);
            if (empty($line) || $line[0] === '#') {
                continue;
            }
            $pos = strpos($line, '=');
            if ($pos !== false) {
                $key = trim(substr($line, 0, $pos));
                $value = trim(substr($line, $pos + 1));
                $value = trim($value, '"\'');
                $parsed[$key] = $value;
            }
        }
        return $parsed;
    }

    private function _checkGitTool()
    {
        try {
            $output = trim($this->_sbin('git-version', []));
            return ['name' => 'git', 'ok' => true, 'version' => $output, 'required' => false];
        } catch (\Throwable $e) {
            return ['name' => 'git', 'ok' => false, 'version' => 'Not found', 'required' => false];
        }
    }

    private function _checkToolAsUser($user, $cmd)
    {
        $name = 'unknown';
        if (preg_match('/(?:&&\s*)?(\w+)\s+--version/', $cmd, $m)) {
            $name = $m[1];
        }
        try {
            $scriptPath = $this->_basePath . '/.xve-toolcheck-' . uniqid() . '.sh';
            $this->_fileManager->filePutContents($scriptPath, "#!/bin/bash\n" . $cmd . "\n");
            $this->_sbin('chmod', ['+x', $scriptPath]);
            $output = trim($this->_sbin('run-script-as-user', [$user, $scriptPath]));
            $this->_sbin('rm-f', [$scriptPath]);
            if (stripos($output, 'not found') !== false || stripos($output, 'No such file') !== false) {
                return ['name' => $name, 'ok' => false, 'version' => 'Not found', 'required' => false];
            }
            return ['name' => $name, 'ok' => true, 'version' => $output, 'required' => false];
        } catch (\Throwable $e) {
            return ['name' => $name, 'ok' => false, 'version' => 'Not found', 'required' => false];
        }
    }

    private function _sbin($subcommand, array $args = [])
    {
        $result = pm_ApiCli::callSbin(self::SBIN_SCRIPT, array_merge([$subcommand], $args));
        return isset($result['stdout']) ? $result['stdout'] : '';
    }

    // ─── Public API for LongTask step-by-step execution ────────

    public function ensureStructure() { $this->_ensureStructure(); }
    public function gitClone($releasePath, $branchOverride = null) { return $this->_gitClone($releasePath, $branchOverride); }
    public function chownRelease($releasePath) { $this->_chownRelease($releasePath); }
    public function linkShared($releasePath) { $this->_linkShared($releasePath); }
    public function switchRelease($releasePath) { $this->_switchRelease($releasePath); }
    public function runDeploySteps($phase, $releasePath) { $this->_runDeploySteps($phase, $releasePath); }
    public function healthCheck() { $this->_healthCheck(); }
    public function cleanup() { $this->_cleanup(); }
    public function removeRelease($releasePath) { $this->_sbin('rm-rf', [$releasePath]); }

    public function parkFailedRelease($releasePath)
    {
        $basePath = rtrim($this->_domain->getHomePath(), '/');
        $parkedPath = $basePath . '/releases/_last_failed_release';

        // Remove any previously parked failed release
        $this->_sbin('rm-rf', [$parkedPath]);
        // Move the failed release to the parked location
        $this->_sbin('mv', [$releasePath, $parkedPath]);
    }
    public function ensureArtisanSymlink() { $this->_ensureArtisanSymlink(); }
    public function ensureStorageLink($releasePath) { $this->_ensureStorageLink($releasePath); }
    public function fixOwnership() { $this->_fixOwnership(); }
    public function addHistory($release, $action, $status, $commit = null) { $this->_addHistory($release, $action, $status, $commit); }

    public function runPreDeployScript($releasePath)
    {
        $this->_runScript($this->_settings->getPreDeployScript(), $releasePath, 'pre-deploy');
    }

    public function runPostDeployScript($releasePath)
    {
        $this->_runScript($this->_settings->getPostDeployScript(), $releasePath, 'post-deploy');
    }

    public function getBasePath()
    {
        return $this->_basePath;
    }
}
