<?php

class Modules_XveLaravelKit_Url
{
    public static function action($action, $params = [])
    {
        $url = '/modules/xve-laravel-kit/index.php/' . $action;
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }
        return $url;
    }
}
