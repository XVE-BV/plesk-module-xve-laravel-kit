<?php

/**
 * PHPUnit bootstrap for the XVE Laravel Kit Plesk extension.
 *
 * The production classes depend on Plesk SDK globals (pm_Settings, pm_Domain,
 * pm_Log, etc.) that are not available outside the Plesk runtime.  We provide
 * minimal stubs here so that the source files can be loaded and the pure-logic
 * methods can be exercised in isolation.
 */

// ── Plesk SDK stubs ──────────────────────────────────────────────────────────

if (!class_exists('pm_Settings')) {
    class pm_Settings
    {
        private static array $store = [];

        public static function get(string $key, $default = null)
        {
            return self::$store[$key] ?? $default;
        }

        public static function set(string $key, $value): void
        {
            self::$store[$key] = $value;
        }

        /** Reset between tests when needed. */
        public static function reset(): void
        {
            self::$store = [];
        }
    }
}

if (!class_exists('pm_Domain')) {
    class pm_Domain
    {
        public function getId(): int { return 1; }
        public static function getAllDomains(): array { return []; }
    }
}

if (!class_exists('pm_Log')) {
    class pm_Log
    {
        public static function info(string $msg): void {}
        public static function err(string $msg): void {}
    }
}

if (!class_exists('pm_Context')) {
    class pm_Context
    {
        public static function getVarDir(): string { return '/tmp/'; }
    }
}

if (!class_exists('pm_ServerFileManager')) {
    class pm_ServerFileManager
    {
        public function fileExists(string $path): bool { return false; }
        public function filePutContents(string $path, string $contents): void {}
        public function fileGetContents(string $path): string { return ''; }
        public function removeDirectory(string $path): void {}
    }
}

if (!class_exists('pm_Form_Simple')) {
    class pm_Form_Simple
    {
        public function __construct() {}
        public function init(): void {}
        public function addElement(string $type, string $name, array $options = []): void {}
        public function addControlButtons(array $options = []): void {}
    }
}

// ── Autoload source classes ──────────────────────────────────────────────────

$libraryDir = __DIR__ . '/../src/plib/library';

require_once $libraryDir . '/DeploySettings.php';
require_once $libraryDir . '/Deployer.php';
