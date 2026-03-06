<?php
pm_Context::init('xve-laravel-kit');
pm_Loader::registerAutoload();
$application = new pm_Application();
$application->run();
