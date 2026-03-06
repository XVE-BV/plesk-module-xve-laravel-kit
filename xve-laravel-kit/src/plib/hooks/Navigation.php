<?php

class Modules_XveLaravelKit_Navigation extends pm_Hook_Navigation
{
    public function getNavigation()
    {
        return [
            [
                'controller' => 'index',
                'action' => 'index',
                'label' => 'XVE Laravel Kit',
            ],
        ];
    }
}
