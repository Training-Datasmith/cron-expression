<?php

declare(strict_types=1);

namespace Cron\Tests;

use Cron\Field_Factory;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\TestCase;

/**
 * @author Michael Dowling <mtdowling@gmail.com>
 */
#[CoversFunction('get_field')]
class Field_Factory_Test extends TestCase
{
    public function testRetrievesFieldInstances(): void
    {
        $mappings = [
            0 => 'Cron\Minutes_Field',
            1 => 'Cron\Hours_Field',
            2 => 'Cron\Day_Of_Month_Field',
            3 => 'Cron\Month_Field',
            4 => 'Cron\Day_Of_Week_Field',
        ];

        $f = new Field_Factory();

        foreach ($mappings as $position => $class) {
            $this->assertInstanceOf($class, $f->get_field($position));
        }
    }

    public function testValidatesFieldPosition(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $f = new Field_Factory();
        $f->get_field(-1);
    }
}
