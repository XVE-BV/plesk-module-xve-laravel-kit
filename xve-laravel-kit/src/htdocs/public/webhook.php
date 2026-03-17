<?php
/**
 * Public webhook endpoint — no Plesk login required.
 *
 * URL: https://<plesk>:8443/modules/xve-laravel-kit/public/webhook.php
 *
 * Usage:
 *   POST ?secret=<token>
 *   POST with Authorization: Bearer <token>
 *   Optional JSON body: {"branch": "main"}
 */

// Bootstrap Plesk SDK and extension autoloading
require_once 'sdk.php';
pm_Context::init('xve-laravel-kit');

header('Content-Type: application/json');

// POST only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Use POST.']);
    exit;
}

// Extract secret from query string or Authorization header
$secret = isset($_GET['secret']) ? $_GET['secret'] : '';
if (empty($secret)) {
    $authHeader = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
    if (strpos($authHeader, 'Bearer ') === 0) {
        $secret = substr($authHeader, 7);
    }
}

if (empty($secret)) {
    http_response_code(401);
    echo json_encode(['error' => 'Missing secret. Pass as ?secret=... or Authorization: Bearer header.']);
    exit;
}

// Look up domain by webhook secret
$settings = Modules_XveLaravelKit_DeploySettings::findByWebhookSecret($secret);
if (!$settings) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid secret.']);
    exit;
}

$domain = $settings->getDomain();

// Parse request body (branch, force)
$branch = '';
$force = false;
$body = file_get_contents('php://input');
if (!empty($body)) {
    $payload = json_decode($body, true);
    if (isset($payload['branch'])) {
        $branch = trim($payload['branch']);
    }
    if (!empty($payload['force'])) {
        $force = true;
    }
}

// Concurrency guard — cancel or reject depending on force flag
if (Modules_XveLaravelKit_Task_Deploy::isLocked($domain->getId())) {
    if ($force) {
        Modules_XveLaravelKit_Task_Deploy::cancelRunning($domain->getId());
    } else {
        http_response_code(409);
        echo json_encode([
            'error'  => 'A deploy is already in progress for this domain. Pass {"force": true} to cancel it and start a new deploy.',
            'domain' => $domain->getDisplayName(),
        ]);
        exit;
    }
}

// Start deploy as async LongTask
$task = new Modules_XveLaravelKit_Task_Deploy();
$task->setParam('domainId', $domain->getId());
if (!empty($branch)) {
    $task->setParam('branch', $branch);
}

$taskManager = new pm_LongTask_Manager();
$taskManager->start($task, $domain);

http_response_code(202);
echo json_encode([
    'success' => true,
    'domain'  => $domain->getDisplayName(),
    'message' => 'Deployment started.',
]);
