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
     * Send a deploy notification to Teams as an Adaptive Card.
     *
     * Posts the Power Automate Workflows envelope ({type: message, attachments:[adaptive card]}),
     * so the configured webhook must be a Teams "Workflow" URL, not a legacy O365 connector.
     *
     * @param string $domainName
     * @param string $release
     * @param string $status      'success' or 'failed'
     * @param string $branch
     * @param array|null $commitInfo  ['hash' => ..., 'message' => ..., 'author' => ...]
     * @param string $error       Error message (for failed deploys)
     * @param string $repoWebUrl  Repo web URL, used for the "View commit" action (optional)
     */
    public static function notifyDeploy($domainName, $release, $status, $branch = '', $commitInfo = null, $error = '', $repoWebUrl = '')
    {
        $webhookUrl = self::getWebhookUrl();
        if (empty($webhookUrl)) {
            return;
        }

        $isSuccess = ($status === 'success');
        $title    = $isSuccess ? 'Deploy succeeded' : 'Deploy failed';
        $icon     = $isSuccess ? "\xe2\x9c\x85" : "\xe2\x9d\x8c"; // ✅ / ❌
        $color    = $isSuccess ? 'Good' : 'Attention';
        $subtitle = $isSuccess ? $domainName . ' is live' : $domainName . ' deploy failed';

        $facts = [
            ['title' => 'Domain', 'value' => $domainName],
            ['title' => 'Release', 'value' => $release],
        ];
        if (!empty($branch)) {
            $facts[] = ['title' => 'Branch', 'value' => $branch];
        }
        if ($commitInfo && !empty($commitInfo['hash'])) {
            $hash = substr($commitInfo['hash'], 0, 7);
            $msg = $commitInfo['message'] ?? '';
            $facts[] = ['title' => 'Commit', 'value' => '`' . $hash . '`' . ($msg ? ' ' . $msg : '')];
            if (!empty($commitInfo['author'])) {
                $facts[] = ['title' => 'Author', 'value' => $commitInfo['author']];
            }
        }
        $facts[] = ['title' => 'Time', 'value' => date('Y-m-d H:i T')];
        if (!$isSuccess && !empty($error)) {
            $facts[] = ['title' => 'Error', 'value' => $error];
        }

        $actions = [];
        if (!empty($domainName)) {
            $actions[] = ['type' => 'Action.OpenUrl', 'title' => 'Open site', 'url' => 'https://' . $domainName];
        }
        if (!empty($repoWebUrl) && $commitInfo && !empty($commitInfo['hash'])) {
            $actions[] = ['type' => 'Action.OpenUrl', 'title' => 'View commit', 'url' => rtrim($repoWebUrl, '/') . '/commit/' . $commitInfo['hash']];
        }

        $body = [
            [
                'type' => 'ColumnSet',
                'columns' => [
                    [
                        'type' => 'Column',
                        'width' => 'auto',
                        'verticalContentAlignment' => 'Center',
                        'items' => [[
                            'type' => 'TextBlock',
                            'text' => $icon,
                            'size' => 'ExtraLarge',
                            'spacing' => 'None',
                        ]],
                    ],
                    [
                        'type' => 'Column',
                        'width' => 'stretch',
                        'items' => [
                            [
                                'type' => 'TextBlock',
                                'text' => $title,
                                'size' => 'Large',
                                'weight' => 'Bolder',
                                'color' => $color,
                                'spacing' => 'None',
                            ],
                            [
                                'type' => 'TextBlock',
                                'text' => $subtitle,
                                'isSubtle' => true,
                                'spacing' => 'None',
                                'wrap' => true,
                            ],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'FactSet',
                'separator' => true,
                'facts' => $facts,
            ],
        ];
        if (!empty($actions)) {
            $body[] = ['type' => 'ActionSet', 'actions' => $actions];
        }

        $card = [
            'type' => 'message',
            'attachments' => [[
                'contentType' => 'application/vnd.microsoft.card.adaptive',
                'contentUrl' => null,
                'content' => [
                    '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                    'type' => 'AdaptiveCard',
                    'version' => '1.4',
                    'msteams' => ['width' => 'Full'],
                    'body' => $body,
                ],
            ]],
        ];

        self::_send($webhookUrl, $card);
    }

    private static function _send($url, array $payload)
    {
        $json = json_encode($payload);

        pm_Log::info('Teams webhook: sending to ' . substr($url, 0, 60) . '...');

        // Use shell curl via callSbin; guaranteed to work from Plesk task context
        // (PHP curl in sw-engine may lack CA certs or curl extension)
        try {
            $result = pm_ApiCli::callSbin('xve-exec.sh', ['curl-teams', $url, $json]);
            $output = isset($result['stdout']) ? trim($result['stdout']) : '';
            pm_Log::info('Teams webhook: sent OK - ' . $output);
        } catch (\Throwable $e) {
            pm_Log::warn('Teams webhook failed: ' . $e->getMessage());
        }
    }
}
