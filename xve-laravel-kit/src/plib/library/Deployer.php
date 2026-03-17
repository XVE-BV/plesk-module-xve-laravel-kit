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
        $this->_exec(sprintf('chown -R %s:%s %s',
            escapeshellarg($user),
            escapeshellarg($group),
            escapeshellarg($sharedPath)
        ));
        $this->_exec(sprintf('find %s -type d -exec chmod 775 {} +', escapeshellarg($sharedPath)));
        $this->_exec(sprintf('find %s -type f -exec chmod 664 {} +', escapeshellarg($sharedPath)));

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
        $this->_exec(sprintf('plesk bin site --update %s -www-root current/public',
            escapeshellarg($domainName)
        ));
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
            $this->_exec(sprintf('plesk bin site --update %s -www-root httpdocs',
                escapeshellarg($domainName)
            ));
        } catch (\Throwable $e) {}

        // Remove current symlink
        $this->_exec('rm -f ' . escapeshellarg($this->_basePath . '/current'));
        $this->_exec('rm -f ' . escapeshellarg($this->_basePath . '/artisan'));
        $this->_exec('rm -f ' . escapeshellarg($this->_basePath . '/' . self::HISTORY_FILE));

        // Ensure httpdocs exists so Plesk has a valid document root
        $httpdocs = $this->_basePath . '/httpdocs';
        if (!$this->_dirExists($httpdocs)) {
            $this->_exec('mkdir -p ' . escapeshellarg($httpdocs));
            $user = $this->_getSystemUser();
            $this->_exec(sprintf('chown %s:%s %s',
                escapeshellarg($user),
                escapeshellarg('psaserv'),
                escapeshellarg($httpdocs)
            ));
        }

        // Archive shared/ before deletion so .env, uploads, and logs can be recovered
        $timestamp = date('Ymd_His');
        $sharedPath = $this->_basePath . '/shared';
        $releasesPath = $this->_basePath . '/releases';

        if ($this->_dirExists($sharedPath)) {
            $sharedArchive = $this->_basePath . '/shared-teardown-' . $timestamp . '.tar.gz';
            try {
                $this->_exec(sprintf(
                    'tar -czf %s -C %s shared',
                    escapeshellarg($sharedArchive),
                    escapeshellarg($this->_basePath)
                ));
                \pm_Log::info('Teardown: shared/ backed up to ' . $sharedArchive);
            } catch (\Throwable $e) {
                \pm_Log::info('Teardown: could not archive shared/ — ' . $e->getMessage());
            }
        }

        if ($this->_dirExists($releasesPath)) {
            $releasesArchive = $this->_basePath . '/releases-teardown-' . $timestamp . '.tar.gz';
            try {
                $this->_exec(sprintf(
                    'tar -czf %s -C %s releases',
                    escapeshellarg($releasesArchive),
                    escapeshellarg($this->_basePath)
                ));
                \pm_Log::info('Teardown: releases/ backed up to ' . $releasesArchive);
            } catch (\Throwable $e) {
                \pm_Log::info('Teardown: could not archive releases/ — ' . $e->getMessage());
            }
        }

        // Remove releases and shared
        $this->_exec('rm -rf ' . escapeshellarg($this->_basePath . '/releases'));
        $this->_exec('rm -rf ' . escapeshellarg($this->_basePath . '/shared'));
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
            $output = $this->_exec('ls -1r ' . escapeshellarg($releasesDir) . ' 2>/dev/null');
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
            // can prune them in a controlled way — preserving rollback capability.
            if (!$release['current'] && $release['status'] === 'failed') {
                $path = $this->_basePath . '/releases/' . $release['name'];
                $this->_exec('rm -rf ' . escapeshellarg($path));
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
            $info['php_version'] = trim($this->_exec($phpBin . ' -r "echo PHP_VERSION;" 2>/dev/null'));
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
        $this->_exec(sprintf('chown %s:psaserv %s', escapeshellarg($user), escapeshellarg($path)));
        $this->_exec(sprintf('chmod 600 %s', escapeshellarg($path)));
    }

    public function deleteComposerAuth()
    {
        $path = $this->getComposerAuthPath();
        if ($this->_fileManager->fileExists($path)) {
            $this->_exec('rm -f ' . escapeshellarg($path));
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
                $this->_exec(sprintf('cp %s %s', escapeshellarg($envPath), escapeshellarg($backup)));
            }
        } catch (\Throwable $e) {}

        // Keep only the 10 most recent .env backups; delete older ones
        try {
            $sharedDir = $this->_basePath . '/shared';
            $output = trim($this->_exec(sprintf(
                'find %s -maxdepth 1 -name ".env.backup.*" -type f 2>/dev/null | sort || true',
                escapeshellarg($sharedDir)
            )));
            if ($output !== '') {
                $backups = array_values(array_filter(explode("\n", $output)));
                $excess = array_slice($backups, 0, max(0, count($backups) - 10));
                foreach ($excess as $old) {
                    $this->_exec('rm -f ' . escapeshellarg($old));
                }
            }
        } catch (\Throwable $e) {}

        $this->_fileManager->filePutContents($envPath, $contents);

        // Chown to system user
        $user = $this->_getSystemUser();
        $this->_exec(sprintf('chown %s:%s %s',
            escapeshellarg($user),
            escapeshellarg('psaserv'),
            escapeshellarg($envPath)
        ));
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
        if (!preg_match('/^[a-zA-Z0-9:_\-\s="\'.,\/]+$/', $command)) {
            return ['success' => false, 'output' => 'Command contains invalid characters. Only alphanumeric characters, colons, hyphens, underscores, equals signs, spaces, quotes, dots, commas, and forward slashes are allowed.'];
        }

        // Block dangerous commands (secondary safety net)
        $blocked = ['migrate:fresh', 'migrate:reset', 'db:wipe', 'db:seed', 'key:generate'];
        $cmdBase = explode(' ', trim($command))[0];
        if (in_array($cmdBase, $blocked)) {
            return ['success' => false, 'output' => "Command '$cmdBase' is blocked for safety. Run it manually via SSH if needed."];
        }

        $phpBinDir = $this->_getPhpBinDir();
        $pathExport = $phpBinDir ? 'export PATH="' . $phpBinDir . ':$PATH" && ' : '';

        try {
            $fullCmd = sprintf(
                'su -s /bin/bash %s -c %s 2>&1',
                escapeshellarg($this->_getSystemUser()),
                escapeshellarg($pathExport . 'cd ' . escapeshellarg($currentPath) . ' && php artisan ' . escapeshellarg($command))
            );
            $output = $this->_exec($fullCmd);
            return ['success' => true, 'output' => $output];
        } catch (\Throwable $e) {
            return ['success' => false, 'output' => $e->getMessage()];
        }
    }

    /**
     * Read the Laravel log file (from shared/storage/logs/).
     */
    public function getLogContents($lines = 200)
    {
        $logPath = $this->_basePath . '/shared/storage/logs/laravel.log';
        try {
            if (!$this->_fileManager->fileExists($logPath)) {
                return '';
            }
            $output = $this->_exec(sprintf('tail -n %d %s 2>/dev/null', (int) $lines, escapeshellarg($logPath)));
            return $output;
        } catch (\Throwable $e) {
            return 'Error reading log: ' . $e->getMessage();
        }
    }

    /**
     * Clear the Laravel log file.
     */
    public function clearLog()
    {
        $logPath = $this->_basePath . '/shared/storage/logs/laravel.log';
        try {
            if ($this->_fileManager->fileExists($logPath)) {
                $this->_exec('truncate -s 0 ' . escapeshellarg($logPath));
            }
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

        $checks['git'] = $this->_checkTool('git', 'git --version');
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
            $result = $this->_exec('test -d ' . escapeshellarg($this->_basePath) . ' && test -w ' . escapeshellarg($this->_basePath) . ' && echo "yes" || echo "no"');
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
            $output = $this->_exec(sprintf(
                'plesk db "SELECT db.name, du.login FROM data_bases db LEFT JOIN db_users du ON du.db_id = db.id WHERE db.dom_id = %d LIMIT 1"',
                (int) $domainId
            ));
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

        foreach ($paths as $path) {
            $this->_exec('mkdir -p ' . escapeshellarg($path));
        }

        // Chown all created directories to the domain system user
        $user = $this->_getSystemUser();
        foreach ($paths as $path) {
            $this->_exec(sprintf('chown %s:%s %s',
                escapeshellarg($user),
                escapeshellarg('psaserv'),
                escapeshellarg($path)
            ));
        }
    }

    public function listRemoteBranches()
    {
        $repo = $this->_settings->getGitRepo();
        if (empty($repo)) {
            return [];
        }

        $envPrefix = '';
        if ($this->_settings->isSshRepo()) {
            Modules_XveLaravelKit_SshKey::ensure($this->_settings);
            $keyPath = $this->_settings->getSshPrivateKeyPath();
            $envPrefix = sprintf(
                'GIT_SSH_COMMAND=%s ',
                escapeshellarg('ssh -i ' . $keyPath . ' -o StrictHostKeyChecking=accept-new')
            );
        }

        $cmd = sprintf('%sgit ls-remote --heads %s 2>&1', $envPrefix, escapeshellarg($repo));
        $output = $this->_exec($cmd);

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

        $envPrefix = '';
        if ($this->_settings->isSshRepo()) {
            Modules_XveLaravelKit_SshKey::ensure($this->_settings);
            $keyPath = $this->_settings->getSshPrivateKeyPath();
            $envPrefix = sprintf(
                'GIT_SSH_COMMAND=%s ',
                escapeshellarg('ssh -i ' . $keyPath . ' -o StrictHostKeyChecking=accept-new')
            );
        }

        try {
            $cmd = sprintf(
                '%sgit archive --remote=%s %s %s 2>/dev/null | tar -xO %s 2>/dev/null',
                $envPrefix,
                escapeshellarg($repo),
                escapeshellarg($branch),
                escapeshellarg($filePath),
                escapeshellarg($filePath)
            );
            return $this->_exec($cmd);
        } catch (\Throwable $e) {
            // git archive may not be supported (e.g. GitHub), try shallow clone fallback
            try {
                $tmpDir = '/tmp/xlk-init-' . uniqid();
                $cloneCmd = sprintf(
                    '%sgit clone --depth 1 --quiet --branch %s --no-checkout %s %s 2>&1 && git -C %s checkout HEAD -- %s 2>&1',
                    $envPrefix,
                    escapeshellarg($branch),
                    escapeshellarg($repo),
                    escapeshellarg($tmpDir),
                    escapeshellarg($tmpDir),
                    escapeshellarg($filePath)
                );
                $this->_exec($cloneCmd);
                $contents = $this->_fileManager->fileGetContents($tmpDir . '/' . $filePath);
                $this->_exec('rm -rf ' . escapeshellarg($tmpDir));
                return $contents;
            } catch (\Throwable $e2) {
                // Cleanup and return empty — file may not exist in repo
                try { $this->_exec('rm -rf ' . escapeshellarg($tmpDir)); } catch (\Throwable $e3) {}
                return '';
            }
        }
    }

    private function _gitClone($releasePath, $branchOverride = null)
    {
        $repo = $this->_settings->getGitRepo();
        $branch = $branchOverride ?: $this->_settings->getBranch();

        $envPrefix = '';
        if ($this->_settings->isSshRepo()) {
            Modules_XveLaravelKit_SshKey::ensure($this->_settings);
            $keyPath = $this->_settings->getSshPrivateKeyPath();
            $envPrefix = sprintf(
                'GIT_SSH_COMMAND=%s ',
                escapeshellarg('ssh -i ' . $keyPath . ' -o StrictHostKeyChecking=accept-new')
            );
        }

        $mode = $this->_settings->getDeployMode();
        $q = ($mode === 'quiet') ? ' --quiet' : '';

        $cmd = sprintf(
            '%sgit clone --depth 1%s --branch %s %s %s 2>&1',
            $envPrefix,
            $q,
            escapeshellarg($branch),
            escapeshellarg($repo),
            escapeshellarg($releasePath)
        );

        $output = $this->_exec($cmd);

        if (!$this->_dirExists($releasePath . '/.git')) {
            throw new pm_Exception('Git clone failed: ' . $output);
        }

        // Capture commit info before removing .git
        $commitHash = trim($this->_exec('git -C ' . escapeshellarg($releasePath) . ' rev-parse HEAD 2>/dev/null'));
        $commitMsg = trim($this->_exec('git -C ' . escapeshellarg($releasePath) . ' log -1 --pretty=%s 2>/dev/null'));
        $commitAuthor = trim($this->_exec('git -C ' . escapeshellarg($releasePath) . ' log -1 --pretty=%an 2>/dev/null'));

        $this->_exec('rm -rf ' . escapeshellarg($releasePath . '/.git'));

        return [
            'hash' => $commitHash,
            'message' => $commitMsg,
            'author' => $commitAuthor,
            'branch' => $branch,
        ];
    }

    private function _linkShared($releasePath)
    {
        foreach ($this->_settings->getSharedDirs() as $dir) {
            $target = $releasePath . '/' . $dir;
            $shared = $this->_basePath . '/shared/' . $dir;
            $parentDir = dirname($target);
            if ($parentDir !== $releasePath) {
                $this->_exec('mkdir -p ' . escapeshellarg($parentDir));
            }
            $this->_exec('rm -rf ' . escapeshellarg($target));
            $this->_exec(sprintf('ln -sfn %s %s', escapeshellarg($shared), escapeshellarg($target)));
        }

        foreach ($this->_settings->getSharedFiles() as $file) {
            $target = $releasePath . '/' . $file;
            $shared = $this->_basePath . '/shared/' . $file;
            if ($this->_fileManager->fileExists($shared)) {
                $parentDir = dirname($target);
                if ($parentDir !== $releasePath) {
                    $this->_exec('mkdir -p ' . escapeshellarg($parentDir));
                }
                $this->_exec('rm -f ' . escapeshellarg($target));
                $this->_exec(sprintf('ln -sfn %s %s', escapeshellarg($shared), escapeshellarg($target)));
            }
        }

        // Auto-link auth.json for Composer private package authentication (e.g. Backpack)
        $authJson = $this->_basePath . '/shared/auth.json';
        if ($this->_fileManager->fileExists($authJson)) {
            $target = $releasePath . '/auth.json';
            $this->_exec('rm -f ' . escapeshellarg($target));
            $this->_exec(sprintf('ln -sfn %s %s', escapeshellarg($authJson), escapeshellarg($target)));
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
            \pm_Log::warning(
                "switchRelease: 'current' is a real directory, moving to backup: {$backupPath}"
            );
            $this->_exec(sprintf('mv %s %s', escapeshellarg($currentLink), escapeshellarg($backupPath)));

            // Keep only the last 2 current-backup-* directories to avoid unbounded growth
            $backupList = glob($this->_basePath . '/current-backup-*', GLOB_ONLYDIR);
            if (is_array($backupList)) {
                sort($backupList);
                $toDelete = array_slice($backupList, 0, max(0, count($backupList) - 2));
                foreach ($toDelete as $old) {
                    $this->_exec('rm -rf ' . escapeshellarg($old));
                }
            }
        }

        // Atomic symlink switch: create temp link, then rename over current
        $this->_exec(sprintf('ln -sfn %s %s', escapeshellarg($releasePath), escapeshellarg($tempLink)));
        $this->_exec(sprintf('mv -Tf %s %s', escapeshellarg($tempLink), escapeshellarg($currentLink)));

        // Fix symlink ownership — nginx's disable_symlinks if_not_owner
        // requires the symlink itself to be owned by the domain user
        $user = $this->_getSystemUser();
        $this->_exec(sprintf('chown -h %s:psaserv %s', escapeshellarg($user), escapeshellarg($currentLink)));
    }

    private function _ensureArtisanSymlink()
    {
        $artisanLink = $this->_basePath . '/artisan';
        $target = 'current/artisan';
        if ($this->_fileManager->fileExists($this->_basePath . '/current/artisan')) {
            $this->_exec(sprintf('ln -sfn %s %s', escapeshellarg($target), escapeshellarg($artisanLink)));
        }
    }

    private function _ensureStorageLink($releasePath)
    {
        $publicStorage = $releasePath . '/public/storage';
        $target = $this->_basePath . '/shared/storage/app/public';
        if ($this->_dirExists($target) && !$this->_fileManager->fileExists($publicStorage)) {
            $this->_exec(sprintf('ln -sfn %s %s', escapeshellarg($target), escapeshellarg($publicStorage)));
        }
    }

    private function _healthCheck()
    {
        $url = $this->_settings->getHealthCheckUrl();
        if (empty($url)) {
            return;
        }

        $timeout = $this->_settings->getHealthCheckTimeout();

        if (strpos($url, '/') === 0) {
            $url = 'http://' . $this->_domain->getDisplayName() . $url;
        }

        $parsedUrl = parse_url($url);
        $domainName = $this->_domain->getDisplayName();
        if (isset($parsedUrl['host']) && $parsedUrl['host'] !== $domainName) {
            throw new pm_Exception('Health check URL must be on the same domain.');
        }

        $cmd = sprintf(
            'curl -sf --max-time %d -o /dev/null -w "%%{http_code}" %s 2>&1',
            (int) $timeout,
            escapeshellarg($url)
        );

        $httpCode = trim($this->_exec($cmd));
        $code = (int) $httpCode;

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
        $this->_exec('chmod +x ' . escapeshellarg($scriptPath));

        $output = $this->_exec(sprintf(
            'su -s /bin/bash %s -c %s 2>&1',
            escapeshellarg($this->_getSystemUser()),
            escapeshellarg('bash ' . $scriptPath)
        ));

        $this->_exec('rm -f ' . escapeshellarg($scriptPath));

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
            $this->_exec('rm -rf ' . escapeshellarg($path));
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
            'user' => pm_Session::getClient()->getProperty('login'),
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
        $this->_exec(sprintf('chown -R %s:%s %s',
            escapeshellarg($user),
            escapeshellarg('psaserv'),
            escapeshellarg($releasePath)
        ));
        $sharedPath = $this->_basePath . '/shared';
        $this->_exec(sprintf('chown -R %s:%s %s',
            escapeshellarg($user),
            escapeshellarg('psaserv'),
            escapeshellarg($sharedPath)
        ));
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
        // and also fixes regular files/dirs. Non-recursive — releases are chowned individually.
        $this->_exec(sprintf(
            'find %s -maxdepth 1 -exec chown -h %s:%s {} + 2>/dev/null || true',
            escapeshellarg($this->_basePath),
            escapeshellarg($user),
            escapeshellarg($group)
        ));
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

    private function _getPhpBinDir()
    {
        try {
            $phpHandler = $this->_domain->getPhpHandlerId();
            if (preg_match('/plesk-php(\d)(\d)/', $phpHandler, $m)) {
                $version = $m[1] . '.' . $m[2];
                $dir = '/opt/plesk/php/' . $version . '/bin';
                if ($this->_dirExists($dir)) {
                    return $dir;
                }
            }
        } catch (\Throwable $e) {}

        try {
            $result = $this->_exec('ls -d /opt/plesk/php/*/bin 2>/dev/null | sort -V | tail -1');
            $dir = trim($result);
            if (!empty($dir)) {
                return $dir;
            }
        } catch (\Throwable $e) {}

        return '';
    }

    private function _dirExists($path)
    {
        try {
            $result = $this->_exec('test -d ' . escapeshellarg($path) . ' && echo "yes" || echo "no"');
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

    private function _checkTool($name, $cmd)
    {
        try {
            $output = trim($this->_exec($cmd . ' 2>&1'));
            return ['name' => $name, 'ok' => true, 'version' => $output, 'required' => false];
        } catch (\Throwable $e) {
            return ['name' => $name, 'ok' => false, 'version' => 'Not found', 'required' => false];
        }
    }

    private function _checkToolAsUser($user, $cmd)
    {
        $name = 'unknown';
        if (preg_match('/(?:&&\s*)?(\w+)\s+--version/', $cmd, $m)) {
            $name = $m[1];
        }
        try {
            $fullCmd = sprintf('su -s /bin/bash %s -c %s 2>&1', escapeshellarg($user), escapeshellarg($cmd));
            $output = trim($this->_exec($fullCmd));
            if (stripos($output, 'not found') !== false || stripos($output, 'No such file') !== false) {
                return ['name' => $name, 'ok' => false, 'version' => 'Not found', 'required' => false];
            }
            return ['name' => $name, 'ok' => true, 'version' => $output, 'required' => false];
        } catch (\Throwable $e) {
            return ['name' => $name, 'ok' => false, 'version' => 'Not found', 'required' => false];
        }
    }

    private function _exec($cmd)
    {
        $result = pm_ApiCli::callSbin(self::SBIN_SCRIPT, [$cmd]);
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
    public function removeRelease($releasePath) { $this->_exec('rm -rf ' . escapeshellarg($releasePath)); }

    public function parkFailedRelease($releasePath)
    {
        $basePath = rtrim($this->_domain->getHomePath(), '/');
        $parkedPath = $basePath . '/releases/_last_failed_release';

        // Remove any previously parked failed release
        $this->_exec('rm -rf ' . escapeshellarg($parkedPath));
        // Move the failed release to the parked location
        $this->_exec('mv ' . escapeshellarg($releasePath) . ' ' . escapeshellarg($parkedPath));
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
