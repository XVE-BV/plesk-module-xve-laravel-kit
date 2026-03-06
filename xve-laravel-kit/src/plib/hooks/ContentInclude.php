<?php

class Modules_XveLaravelKit_ContentInclude extends pm_Hook_ContentInclude
{
    public function getBodyContent()
    {
        $deploying = pm_Settings::get('xlk_deploying', '');
        if (empty($deploying)) {
            return '';
        }

        $data = json_decode($deploying, true);
        if (!$data || !isset($data['domain'])) {
            return '';
        }

        // Clear stale banners (older than 10 minutes)
        if (isset($data['started']) && (time() - $data['started']) > 600) {
            pm_Settings::set('xlk_deploying', '');
            return '';
        }

        $domain = htmlspecialchars($data['domain'], ENT_QUOTES, 'UTF-8');
        $user = htmlspecialchars($data['user'] ?? 'admin', ENT_QUOTES, 'UTF-8');

        return <<<HTML
<div id="xlk-deploy-banner" style="
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    z-index: 99999;
    background: linear-gradient(135deg, #1e3a5f, #2563eb);
    color: #fff;
    padding: 10px 20px;
    font-size: 13px;
    text-align: center;
    box-shadow: 0 2px 8px rgba(0,0,0,0.2);
    animation: xlk-pulse 2s ease-in-out infinite;
">
    <strong>⚡ Deployment in progress</strong> &mdash;
    <strong>{$domain}</strong> is being deployed by <strong>{$user}</strong>.
    Please wait before making changes to this domain.
</div>
<style>
@keyframes xlk-pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.85; }
}
body { margin-top: 42px !important; }
</style>
HTML;
    }
}
