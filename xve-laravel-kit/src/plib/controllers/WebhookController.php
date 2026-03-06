<?php

class WebhookController extends pm_Controller_Action
{
    protected $_accessLevel = 'anonym';

    public function init()
    {
        parent::init();
        $this->_helper->layout()->disableLayout();
        $this->_helper->viewRenderer->setNoRender(true);
    }

    public function deployAction()
    {
        $response = $this->getResponse();
        $response->setHeader('Content-Type', 'application/json');

        if (!$this->getRequest()->isPost()) {
            $response->setHttpResponseCode(405);
            $response->setBody(json_encode(['error' => 'Method not allowed. Use POST.']));
            return;
        }

        $secret = $this->getRequest()->getParam('secret');
        if (empty($secret)) {
            $authHeader = $this->getRequest()->getHeader('Authorization');
            if ($authHeader && strpos($authHeader, 'Bearer ') === 0) {
                $secret = substr($authHeader, 7);
            }
        }

        if (empty($secret)) {
            $response->setHttpResponseCode(401);
            $response->setBody(json_encode(['error' => 'Missing secret. Pass as ?secret=... or Authorization: Bearer header.']));
            return;
        }

        $settings = Modules_XveLaravelKit_DeploySettings::findByWebhookSecret($secret);
        if (!$settings) {
            $response->setHttpResponseCode(403);
            $response->setBody(json_encode(['error' => 'Invalid secret.']));
            return;
        }

        $domain = $settings->getDomain();
        $deployer = new Modules_XveLaravelKit_Deployer($domain, $settings);

        try {
            $result = $deployer->deploy();
            $statusCode = $result['success'] ? 200 : 500;
            $response->setHttpResponseCode($statusCode);
            $response->setBody(json_encode([
                'success' => $result['success'],
                'domain' => $domain->getDisplayName(),
                'release' => isset($result['release']) ? $result['release'] : null,
                'error' => isset($result['error']) ? $result['error'] : null,
            ]));
        } catch (\Throwable $e) {
            $response->setHttpResponseCode(500);
            $response->setBody(json_encode([
                'success' => false,
                'domain' => $domain->getDisplayName(),
                'error' => $e->getMessage(),
            ]));
        }
    }
}
