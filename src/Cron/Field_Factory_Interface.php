<?php

declare (strict_types=1);
namespace Cron;

interface Field_Factory_Interface
{
    public function get_field(int $position): Field_Interface;
}