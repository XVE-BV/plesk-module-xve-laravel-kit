<?php

class Modules_XveLaravelKit_CustomButtons extends pm_Hook_CustomButtons
{
    public function getButtons()
    {
        return [
            [
                'place' => self::PLACE_DOMAIN_PROPERTIES_DYNAMIC,
                'section' => self::SECTION_DOMAIN_PROPS_DYNAMIC_DEV_TOOLS,
                'title' => 'XVE Laravel Kit',
                'description' => 'Laravel deployments, artisan, .env editor',
                'icon' => pm_Context::getBaseUrl() . 'images/logo.svg',
                'link' => rtrim(pm_Context::getBaseUrl(), '/') . '/index.php/domain/index',
                'contextParams' => true,
            ],
            [
                'place' => self::PLACE_ADD_DOMAIN_DRAWER,
                'title' => 'Laravel',
                'description' => 'Create a new site with zero-downtime deployments, artisan, and .env management.',
                'icon' => pm_Context::getBaseUrl() . 'images/logo.svg',
                'link' => rtrim(pm_Context::getBaseUrl(), '/') . '/index.php/index/index',
            ],
        ];
    }
}
