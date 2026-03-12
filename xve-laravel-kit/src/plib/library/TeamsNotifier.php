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

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            pm_Log::warning('Teams webhook failed (HTTP ' . $httpCode . '): ' . $result);
        }
    }
}
