<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for the artisan command validation logic in Modules_XveLaravelKit_Deployer.
 *
 * Two concerns are tested here:
 *   1. The ARTISAN_COMMAND_PATTERN regex correctly distinguishes safe commands
 *      from shell-injection attempts.
 *   2. The ARTISAN_BLOCKED_COMMANDS list contains the expected dangerous commands.
 *
 * Because Modules_XveLaravelKit_Deployer cannot be instantiated without a live
 * Plesk environment, both tests operate on the public class constants directly,
 * which is the actual data used at runtime.
 */
class ArtisanCommandValidationTest extends TestCase
{
    // ── Helper ───────────────────────────────────────────────────────────────

    /**
     * Returns true when $command matches the allowlist pattern (i.e. the
     * command would be permitted to run).
     */
    private function isAllowed(string $command): bool
    {
        return (bool) preg_match(Modules_XveLaravelKit_Deployer::ARTISAN_COMMAND_PATTERN, $command);
    }

    // ── Allowlist pattern: valid commands that must pass ─────────────────────

    public function testSimpleCommandPasses(): void
    {
        $this->assertTrue($this->isAllowed('migrate'), '"migrate" must pass the pattern');
    }

    public function testCacheCommandPasses(): void
    {
        $this->assertTrue($this->isAllowed('cache:clear'), '"cache:clear" must pass the pattern');
    }

    public function testRouteListPasses(): void
    {
        $this->assertTrue($this->isAllowed('route:list'), '"route:list" must pass the pattern');
    }

    public function testMigrateWithFlagPasses(): void
    {
        $this->assertTrue($this->isAllowed('migrate --force'), '"migrate --force" must pass the pattern');
    }

    public function testMigrateWithSeedFlagPasses(): void
    {
        $this->assertTrue($this->isAllowed('migrate --seed'), '"migrate --seed" must pass the pattern');
    }

    public function testCommandWithNamedOptionPasses(): void
    {
        $this->assertTrue($this->isAllowed('db:seed --class=DatabaseSeeder'), '"db:seed --class=DatabaseSeeder" must pass the pattern');
    }

    public function testQueueWorkPasses(): void
    {
        $this->assertTrue($this->isAllowed('queue:work'), '"queue:work" must pass the pattern');
    }

    public function testOptimizePasses(): void
    {
        $this->assertTrue($this->isAllowed('optimize'), '"optimize" must pass the pattern');
    }

    public function testConfigClearPasses(): void
    {
        $this->assertTrue($this->isAllowed('config:clear'), '"config:clear" must pass the pattern');
    }

    // ── Allowlist pattern: shell injection that must be blocked ──────────────

    public function testSemicolonInjectionFails(): void
    {
        $this->assertFalse(
            $this->isAllowed('list; rm -rf /'),
            '"list; rm -rf /" must fail (semicolon injection)'
        );
    }

    public function testPipeInjectionFails(): void
    {
        $this->assertFalse(
            $this->isAllowed('list | cat /etc/passwd'),
            '"list | cat /etc/passwd" must fail (pipe injection)'
        );
    }

    public function testBacktickInjectionFails(): void
    {
        $this->assertFalse(
            $this->isAllowed('list `id`'),
            '"list `id`" must fail (backtick injection)'
        );
    }

    public function testDollarParenSubshellFails(): void
    {
        $this->assertFalse(
            $this->isAllowed('list $(id)'),
            '"list $(id)" must fail ($() subshell injection)'
        );
    }

    public function testAmpersandInjectionFails(): void
    {
        $this->assertFalse(
            $this->isAllowed('list && id'),
            '"list && id" must fail (ampersand chaining)'
        );
    }

    public function testRedirectInjectionFails(): void
    {
        $this->assertFalse(
            $this->isAllowed('list > /etc/crontab'),
            '"list > /etc/crontab" must fail (output redirection)'
        );
    }

    public function testNewlineInjectionFails(): void
    {
        $this->assertFalse(
            $this->isAllowed("migrate\nrm -rf /"),
            'Embedded newline must fail'
        );
    }

    public function testNewlineWithSafeCharsInjectionFails(): void
    {
        $this->assertFalse(
            $this->isAllowed("migrate\nid"),
            '"migrate\\nid" must fail (newline injection with safe chars only)'
        );
    }

    public function testTabInjectionFails(): void
    {
        $this->assertFalse(
            $this->isAllowed("migrate\tid"),
            '"migrate\\tid" must fail (tab injection)'
        );
    }

    public function testCarriageReturnInjectionFails(): void
    {
        $this->assertFalse(
            $this->isAllowed("migrate\rid"),
            '"migrate\\rid" must fail (carriage return injection)'
        );
    }

    public function testHashCommentInjectionFails(): void
    {
        $this->assertFalse(
            $this->isAllowed('migrate # some comment'),
            '"migrate # some comment" must fail (hash injection)'
        );
    }

    // ── Blocked commands list ────────────────────────────────────────────────

    public function testMigrateFreshIsBlocked(): void
    {
        $this->assertContains(
            'migrate:fresh',
            Modules_XveLaravelKit_Deployer::ARTISAN_BLOCKED_COMMANDS,
            '"migrate:fresh" must be in the blocked list'
        );
    }

    public function testMigrateResetIsBlocked(): void
    {
        $this->assertContains(
            'migrate:reset',
            Modules_XveLaravelKit_Deployer::ARTISAN_BLOCKED_COMMANDS,
            '"migrate:reset" must be in the blocked list'
        );
    }

    public function testDbWipeIsBlocked(): void
    {
        $this->assertContains(
            'db:wipe',
            Modules_XveLaravelKit_Deployer::ARTISAN_BLOCKED_COMMANDS,
            '"db:wipe" must be in the blocked list'
        );
    }

    public function testDbSeedIsBlocked(): void
    {
        $this->assertContains(
            'db:seed',
            Modules_XveLaravelKit_Deployer::ARTISAN_BLOCKED_COMMANDS,
            '"db:seed" must be in the blocked list'
        );
    }

    public function testKeyGenerateIsBlocked(): void
    {
        $this->assertContains(
            'key:generate',
            Modules_XveLaravelKit_Deployer::ARTISAN_BLOCKED_COMMANDS,
            '"key:generate" must be in the blocked list'
        );
    }

    // ── Blocked commands are not individually exempted by the pattern ────────

    /**
     * Blocked commands are syntactically valid artisan commands, so they should
     * pass the pattern check and only be stopped by the explicit denylist.
     * This confirms the two-layer approach: allowlist pattern + denylist.
     */
    public function testBlockedCommandsAreGrammaticallyValid(): void
    {
        foreach (Modules_XveLaravelKit_Deployer::ARTISAN_BLOCKED_COMMANDS as $cmd) {
            $this->assertTrue(
                $this->isAllowed($cmd),
                "Blocked command \"$cmd\" should pass the pattern (it is stopped by the denylist, not the regex)"
            );
        }
    }
}
