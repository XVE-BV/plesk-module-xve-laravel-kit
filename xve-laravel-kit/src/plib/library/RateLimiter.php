<?php
/**
 * Per-key sliding-window rate limiter backed by the extension var dir.
 *
 * Uses exclusive file locks (flock) around every read-modify-write cycle to
 * remain safe under concurrent requests. On any IO failure the limiter fails
 * open, so a storage problem can never block a legitimate deploy.
 */
class Modules_XveLaravelKit_RateLimiter
{
    /**
     * Check whether the given key is within the allowed rate, and record the hit.
     *
     * @param  string $key            Arbitrary client identifier (e.g. an IP address).
     * @param  int    $maxRequests    Maximum requests allowed within $windowSeconds.
     * @param  int    $windowSeconds  Rolling window length in seconds.
     * @return bool   true  = request is permitted (hit recorded).
     *                false = limit exceeded (hit not recorded).
     */
    public static function allow($key, $maxRequests = 20, $windowSeconds = 60)
    {
        $dir = pm_Context::getVarDir() . 'webhook-ratelimit/';

        // Create the storage directory on first use.
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        // Key the counter file on a SHA-256 of the raw key so a raw IP never
        // becomes part of the filesystem path and there are no path-traversal risks.
        $file = $dir . hash('sha256', $key) . '.json';

        $fh = @fopen($file, 'c+');
        if ($fh === false) {
            // Cannot open the file; fail open so storage issues don't break deploys.
            return true;
        }

        if (!flock($fh, LOCK_EX)) {
            fclose($fh);
            return true;
        }

        try {
            $now    = time();
            $raw    = stream_get_contents($fh);
            $data   = ($raw !== false && $raw !== '') ? json_decode($raw, true) : null;

            // Initialise or reset when the window has expired.
            if (!is_array($data) || ($now - $data['window_start']) >= $windowSeconds) {
                $data = ['window_start' => $now, 'count' => 0];
            }

            if ($data['count'] >= $maxRequests) {
                return false;
            }

            $data['count']++;

            rewind($fh);
            ftruncate($fh, 0);
            fwrite($fh, json_encode($data));
            fflush($fh);

            return true;
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /**
     * Return how many seconds remain in the current window for the given key.
     *
     * Returns 0 when no window is active (window expired or file absent).
     * Always fails open: returns 0 on any IO error.
     *
     * @param  string $key
     * @param  int    $windowSeconds Must match the value passed to allow().
     * @return int    Seconds until the window resets (>= 0).
     */
    public static function retryAfter($key, $windowSeconds = 60)
    {
        $dir  = pm_Context::getVarDir() . 'webhook-ratelimit/';
        $file = $dir . hash('sha256', $key) . '.json';

        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            return 0;
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['window_start'])) {
            return 0;
        }

        $elapsed   = time() - $data['window_start'];
        $remaining = $windowSeconds - $elapsed;

        return $remaining > 0 ? (int) $remaining : 0;
    }
}
