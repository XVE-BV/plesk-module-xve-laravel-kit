<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for Modules_XveLaravelKit_DeploySettings::validateRepoUrl().
 *
 * Only scp-style SSH URLs to an admin-configured GitHub org on github.com are
 * accepted. The allowed orgs are an extension-level setting; with none set the
 * validator fails closed. HTTPS, other hosts, other owners, traversal, and
 * malformed input are all rejected.
 */
class RepoUrlValidationTest extends TestCase
{
    protected function setUp(): void
    {
        // Configure a single allowed org for the duration of each test.
        Modules_XveLaravelKit_DeploySettings::setAllowedRepoOwners('allowed-org');
    }

    // ── Valid URLs that must be accepted ─────────────────────────────────────

    public function testValidUrlWithDotGit(): void
    {
        $this->assertTrue(
            Modules_XveLaravelKit_DeploySettings::validateRepoUrl('git@github.com:allowed-org/repo.git'),
            'Standard scp SSH URL with .git suffix must be accepted'
        );
    }

    public function testValidUrlWithoutDotGit(): void
    {
        $this->assertTrue(
            Modules_XveLaravelKit_DeploySettings::validateRepoUrl('git@github.com:allowed-org/my-project'),
            'scp SSH URL without .git suffix must be accepted'
        );
    }

    public function testValidUrlWithHyphensAndDots(): void
    {
        $this->assertTrue(
            Modules_XveLaravelKit_DeploySettings::validateRepoUrl('git@github.com:allowed-org/my.repo-name_v2.git'),
            'Repo names with hyphens, dots, and underscores must be accepted'
        );
    }

    public function testSecondConfiguredOrgIsAccepted(): void
    {
        Modules_XveLaravelKit_DeploySettings::setAllowedRepoOwners("allowed-org\nsecond-org");
        $this->assertTrue(
            Modules_XveLaravelKit_DeploySettings::validateRepoUrl('git@github.com:second-org/repo.git'),
            'Any org in the configured allowlist must be accepted'
        );
    }

    // ── Invalid: no allowlist configured (fail closed) ───────────────────────

    public function testRejectedWhenNoOwnerConfigured(): void
    {
        Modules_XveLaravelKit_DeploySettings::setAllowedRepoOwners('');
        $this->assertFalse(
            Modules_XveLaravelKit_DeploySettings::validateRepoUrl('git@github.com:allowed-org/repo.git'),
            'A well-formed URL must be rejected when no org is configured'
        );
    }

    // ── Invalid: wrong protocol ───────────────────────────────────────────────

    public function testHttpsUrlIsRejected(): void
    {
        $this->assertFalse(
            Modules_XveLaravelKit_DeploySettings::validateRepoUrl('https://github.com/allowed-org/repo.git'),
            'HTTPS URL must be rejected'
        );
    }

    public function testSshProtocolUrlIsRejected(): void
    {
        $this->assertFalse(
            Modules_XveLaravelKit_DeploySettings::validateRepoUrl('ssh://git@github.com/allowed-org/repo.git'),
            'ssh:// protocol URL must be rejected (only scp-style git@ is accepted)'
        );
    }

    // ── Invalid: wrong owner ──────────────────────────────────────────────────

    public function testOtherOwnerIsRejected(): void
    {
        $this->assertFalse(
            Modules_XveLaravelKit_DeploySettings::validateRepoUrl('git@github.com:evilcorp/repo.git'),
            'URL with a non-allowlisted owner must be rejected'
        );
    }

    // ── Invalid: wrong host ───────────────────────────────────────────────────

    public function testOtherHostIsRejected(): void
    {
        $this->assertFalse(
            Modules_XveLaravelKit_DeploySettings::validateRepoUrl('git@gitlab.com:allowed-org/repo.git'),
            'URL pointing to gitlab.com must be rejected'
        );
    }

    // ── Invalid: empty / whitespace ───────────────────────────────────────────

    public function testEmptyStringIsRejected(): void
    {
        $this->assertFalse(
            Modules_XveLaravelKit_DeploySettings::validateRepoUrl(''),
            'Empty string must be rejected'
        );
    }

    public function testWhitespaceOnlyIsRejected(): void
    {
        $this->assertFalse(
            Modules_XveLaravelKit_DeploySettings::validateRepoUrl('   '),
            'Whitespace-only string must be rejected'
        );
    }

    // ── Invalid: null ─────────────────────────────────────────────────────────

    public function testNullIsRejected(): void
    {
        $this->assertFalse(
            Modules_XveLaravelKit_DeploySettings::validateRepoUrl(null),
            'null must be rejected'
        );
    }

    // ── Invalid: null byte injection ──────────────────────────────────────────

    public function testNullByteIsRejected(): void
    {
        $this->assertFalse(
            Modules_XveLaravelKit_DeploySettings::validateRepoUrl("git@github.com:allowed-org/repo\0.git"),
            'URL containing a null byte must be rejected'
        );
    }

    // ── Invalid: path traversal ───────────────────────────────────────────────

    public function testPathTraversalInRepoNameIsRejected(): void
    {
        $this->assertFalse(
            Modules_XveLaravelKit_DeploySettings::validateRepoUrl('git@github.com:allowed-org/../etc/passwd'),
            'Path traversal attempt in URL must be rejected'
        );
    }
}
