<?php

declare(strict_types=1);

namespace Cron;

interface FieldFactoryInterface
{
    public function getField(int $position): FieldInterface;
}
