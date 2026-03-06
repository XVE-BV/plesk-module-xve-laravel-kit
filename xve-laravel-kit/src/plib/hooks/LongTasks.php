<?php

class Modules_XveLaravelKit_LongTasks extends pm_Hook_LongTasks
{
    public function getLongTasks()
    {
        return [
            new Modules_XveLaravelKit_Task_Deploy(),
        ];
    }
}
