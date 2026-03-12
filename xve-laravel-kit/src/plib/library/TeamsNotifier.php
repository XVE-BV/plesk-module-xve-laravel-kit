<?php

class Modules_XveLaravelKit_TeamsNotifier
{
    const SETTING_WEBHOOK_URL = 'xlk_teams_webhook_url';

    public static function getWebhookUrl()
    {
        return pm_Settings::get(self::SETTING_WEBHOOK_URL, '');
    }

    public static function setWebhookUrl($url)
    {
        pm_Settings::set(self::SETTING_WEBHOOK_URL, trim($url));
    }

    /**
     * Send a deploy notification to Teams.
     *
     * @param string $domainName
     * @param string $release
     * @param string $status  'success' or 'failed'
     * @param string $branch
     * @param array|null $commitInfo  ['hash' => ..., 'message' => ..., 'author' => ...]
     * @param string $error   Error message (for failed deploys)
     */
    public static function notifyDeploy($domainName, $release, $status, $branch = '', $commitInfo = null, $error = '')
    {
        $webhookUrl = self::getWebhookUrl();
        if (empty($webhookUrl)) {
            return;
        }

        $isSuccess = ($status === 'success');
        $themeColor = $isSuccess ? '2e7d32' : 'e74c3c';
        $statusText = $isSuccess ? 'Deployed successfully' : 'Deploy failed';
        $statusIcon = $isSuccess ? "\xe2\x9c\x85" : "\xe2\x9d\x8c";

        $facts = [
            ['name' => 'Domain', 'value' => $domainName],
            ['name' => 'Release', 'value' => $release],
            ['name' => 'Status', 'value' => $statusIcon . ' ' . $statusText],
        ];

        if (!empty($branch)) {
            $facts[] = ['name' => 'Branch', 'value' => $branch];
        }

        if ($commitInfo && !empty($commitInfo['hash'])) {
            $hash = substr($commitInfo['hash'], 0, 7);
            $msg = $commitInfo['message'] ?? '';
            $facts[] = ['name' => 'Commit', 'value' => $hash . ($msg ? ' — ' . $msg : '')];
            if (!empty($commitInfo['author'])) {
                $facts[] = ['name' => 'Author', 'value' => $commitInfo['author']];
            }
        }

        if (!$isSuccess && !empty($error)) {
            $facts[] = ['name' => 'Error', 'value' => $error];
        }

        $card = [
            '@type' => 'MessageCard',
            '@context' => 'http://schema.org/extensions',
            'themeColor' => $themeColor,
            'summary' => $domainName . ' — ' . $statusText,
            'sections' => [[
                'activityTitle' => $domainName,
                'activitySubtitle' => $statusText,
                'facts' => $facts,
                'markdown' => true,
            ]],
        ];

        self::_send($webhookUrl, $card);
    }

    private static function _send($url, array $payload)
    {
        $json = json_encode($payload);

        pm_Log::info('Teams webhook: sending to ' . substr($url, 0, 60) . '...');

        // Use shell curl via callSbin — guaranteed to work from Plesk task context
        // (PHP curl in sw-engine may lack CA certs or curl extension)
        $cmd = sprintf(
            'curl -sf -m 10 -H %s -d %s %s 2>&1',
            escapeshellarg('Content-Type: application/json'),
            escapeshellarg($json),
            escapeshellarg($url)
        );

        try {
            $result = pm_ApiCli::callSbin('xve-exec.sh', [$cmd]);
            $output = isset($result['stdout']) ? trim($result['stdout']) : '';
            pm_Log::info('Teams webhook: sent OK — ' . $output);
        } catch (\Throwable $e) {
            pm_Log::warning('Teams webhook failed: ' . $e->getMessage());
        }
    }
}
