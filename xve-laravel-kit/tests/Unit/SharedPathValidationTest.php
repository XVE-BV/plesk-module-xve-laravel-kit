<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for Modules_XveLaravelKit_DeploySettings::validateSharedPath().
 *
 * The method guards the shared directory / file lists against directory
 * traversal, absolute paths, null-byte injection, and other unsafe input.
 */
class SharedPathValidationTest extends TestCase
{
    // ── Invalid paths that must be rejected ─────────────────────────────────

    public function testEmptyStringIsInvalid(): void
    {
        $this->assertFalse(
            Modules_XveLaravelKit_DeploySettings::validateSharedPath(''),
            'Empty string must be rejected'
        );
    }

    public function testWhitespaceOnlyIsInvalid(): void
    {
        $this->assertFalse(
            Modules_XveLaravelKit_DeploySettings::validateSharedPath('   '),
            'Whitespace-only string must be rejected'
        );
    }

    public function testSingleDotIsInvalid(): void
    {
        $this->assertFalse(
            Modules_XveLaravelKit_DeploySettings::validateSharedPath('.'),
            '"." must be rejected'
        );
    }

    public function testDoubleDotIsInvalid(): void
    {
        $this->assertFalse(
            Modules_XveLaravelKit_DeploySettings::validateSharedPath('..'),
            '".." must be rejected'
        );
    }

    public function testAbsolutePathIsInvalid(): void
    {
        $this->assertFalse(
            Modules_XveLaravelKit_DeploySettings::validateSharedPath('/etc/passwd'),
            'Absolute paths starting with "/" must be rejected'
        );
    }

    public function testAbsolutePathRootSlashIsInvalid(): void
    {
        $this->assertFalse(
            Modules_XveLaravelKit_DeploySettings::validateSharedPath('/'),
            'Root "/" must be rejected'
        );
    }

    public function testNullByteIsInvalid(): void
    {
        $this->assertFalse(
            Modules_XveLaravelKit_DeploySettings::validateSharedPath("foo\0bar"),
            'Paths containing a null byte must be rejected'
        );
    }

    public function testNullByteAtStartIsInvalid(): void
    {
        $this->assertFalse(
            Modules_XveLaravelKit_DeploySettings::validateSharedPath("\0storage"),
            'Paths beginning with a null byte must be rejected'
        );
    }

    public function testTraversalSegmentInMiddleIsInvalid(): void
    {
        $this->assertFalse(
            Modules_XveLaravelKit_DeploySettings::validateSharedPath('foo/../bar'),
            '"foo/../bar" must be rejected'
        );
    }

    public function testTraversalAtStartIsInvalid(): void
    {
        $this->assertFalse(
            Modules_XveLaravelKit_DeploySettings::validateSharedPath('../etc/passwd'),
            '"../etc/passwd" must be rejected'
        );
    }

    public function testCurrentDirSegmentIsInvalid(): void
    {
        $this->assertFalse(
            Modules_XveLaravelKit_DeploySettings::validateSharedPath('foo/./bar'),
            '"foo/./bar" must be rejected'
        );
    }

    public function testCurrentDirAtStartIsInvalid(): void
    {
        $this->assertFalse(
            Modules_XveLaravelKit_DeploySettings::validateSharedPath('./storage'),
            '"./storage" must be rejected'
        );
    }

    public function testNonStringNullIsInvalid(): void
    {
        $this->assertFalse(
            Modules_XveLaravelKit_DeploySettings::validateSharedPath(null),
            'null must be rejected'
        );
    }

    // ── Valid paths that must be accepted ────────────────────────────────────

    public function testStorageIsValid(): void
    {
        $this->assertTrue(
            Modules_XveLaravelKit_DeploySettings::validateSharedPath('storage'),
            '"storage" must be accepted'
        );
    }

    public function testStorageLogsIsValid(): void
    {
        $this->assertTrue(
            Modules_XveLaravelKit_DeploySettings::validateSharedPath('storage/logs'),
            '"storage/logs" must be accepted'
        );
    }

    public function testBootstrapCacheIsValid(): void
    {
        $this->assertTrue(
            Modules_XveLaravelKit_DeploySettings::validateSharedPath('bootstrap/cache'),
            '"bootstrap/cache" must be accepted'
        );
    }

    public function testDotEnvFileIsValid(): void
    {
        $this->assertTrue(
            Modules_XveLaravelKit_DeploySettings::validateSharedPath('.env'),
            '".env" (dot-prefixed filename without slash) must be accepted'
        );
    }

    public function testDeeplyNestedPathIsValid(): void
    {
        $this->assertTrue(
            Modules_XveLaravelKit_DeploySettings::validateSharedPath('storage/app/public'),
            '"storage/app/public" must be accepted'
        );
    }

    public function testLogsIsValid(): void
    {
        $this->assertTrue(
            Modules_XveLaravelKit_DeploySettings::validateSharedPath('logs'),
            '"logs" must be accepted'
        );
    }

    public function testUploadsIsValid(): void
    {
        $this->assertTrue(
            Modules_XveLaravelKit_DeploySettings::validateSharedPath('uploads'),
            '"uploads" must be accepted'
        );
    }

    /**
     * Leading/trailing whitespace is trimmed inside the method, so a path that
     * is valid after trimming should still pass.
     */
    public function testPathWithSurroundingWhitespaceIsValid(): void
    {
        $this->assertTrue(
            Modules_XveLaravelKit_DeploySettings::validateSharedPath('  storage  '),
            '"  storage  " (with surrounding spaces) must be accepted after trimming'
        );
    }
}
