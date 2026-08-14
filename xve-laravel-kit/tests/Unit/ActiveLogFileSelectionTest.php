<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for Modules_XveLaravelKit_Deployer::selectActiveLogFile().
 *
 * The Log tab used to read shared/storage/logs/laravel.log unconditionally.
 * That file only exists on Monolog's `single` channel; an app on the `daily`
 * channel writes laravel-Y-m-d.log and never creates laravel.log at all, so
 * the tab reported "Log file is empty or does not exist yet" permanently no
 * matter how much the app was logging (observed on btb-app-core, whose log
 * directory held 3.2 MB per day).
 *
 * selectActiveLogFile() is static and pure precisely so these rules can be
 * exercised without a live Plesk environment, matching how the other
 * validation logic in this extension is tested.
 */
class ActiveLogFileSelectionTest extends TestCase
{
    /** `ls -1r` output for a daily-channel app, newest first. */
    private const DAILY_LISTING = "stock-load.log\nlaravel-2026-08-14.log\nlaravel-2026-08-13.log\nlaravel-2026-08-01.log\nexchange-rate.log";

    private function select(string $listing): ?string
    {
        return Modules_XveLaravelKit_Deployer::selectActiveLogFile($listing);
    }

    // ── Daily channel ────────────────────────────────────────────────────────

    public function testPicksNewestDatedFileOnDailyChannel(): void
    {
        $this->assertSame(
            'laravel-2026-08-14.log',
            $this->select(self::DAILY_LISTING),
            'the newest dated log must be chosen on the daily channel'
        );
    }

    /**
     * The production path feeds `ls -1r` output, but the choice must not
     * silently depend on the shell's sort order.
     */
    public function testPicksNewestDatedFileRegardlessOfListingOrder(): void
    {
        $ascending = "exchange-rate.log\nlaravel-2026-08-01.log\nlaravel-2026-08-13.log\nlaravel-2026-08-14.log";

        $this->assertSame(
            'laravel-2026-08-14.log',
            $this->select($ascending),
            'ascending input must still yield the newest dated log'
        );
    }

    public function testIgnoresUnrelatedLogsInTheSameDirectory(): void
    {
        $this->assertNull(
            $this->select("stock-load.log\nexchange-rate.log\nworker.log"),
            'non-Laravel logs in the same directory must never be selected'
        );
    }

    // ── Single channel ───────────────────────────────────────────────────────

    public function testPrefersSingleChannelFileWhenPresent(): void
    {
        $this->assertSame(
            'laravel.log',
            $this->select("laravel-2026-08-14.log\nlaravel.log"),
            'laravel.log must win when both channels have written'
        );
    }

    public function testPicksSingleChannelFileWhenItIsTheOnlyOne(): void
    {
        $this->assertSame('laravel.log', $this->select('laravel.log'));
    }

    // ── Empty / malformed input ──────────────────────────────────────────────

    /**
     * `ls` is silenced on a missing directory, so an empty string is the
     * normal signal for "no logs directory" as well as "no logs yet".
     */
    public function testReturnsNullForEmptyListing(): void
    {
        $this->assertNull($this->select(''));
        $this->assertNull($this->select("\n\n  \n"));
    }

    public function testIgnoresMalformedDateSuffixes(): void
    {
        $this->assertNull(
            $this->select("laravel-2026-08.log\nlaravel-.log\nlaravel-20260814.log"),
            'only fully-formed Y-m-d suffixes are Laravel daily logs'
        );
    }

    public function testToleratesSurroundingWhitespace(): void
    {
        $this->assertSame(
            'laravel-2026-08-14.log',
            $this->select("  laravel-2026-08-14.log  \n  laravel-2026-08-13.log  ")
        );
    }
}
