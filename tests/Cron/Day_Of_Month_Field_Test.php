<?php

declare(strict_types=1);

namespace Cron\Tests;

use Cron\Day_Of_Month_Field;
use DateTime;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\TestCase;

/**
 * @author Michael Dowling <mtdowling@gmail.com>
 */
#[CoversFunction('increment')]
#[CoversFunction('validate')]
#[CoversFunction('is_satisfied_by')]
class Day_Of_Month_Field_Test extends TestCase
{
    public function testValidatesField(): void
    {
        $f = new Day_Of_Month_Field();
        $this->assertTrue($f->validate('1'));
        $this->assertTrue($f->validate('*'));
        $this->assertTrue($f->validate('L'));
        $this->assertTrue($f->validate('5W'));
        $this->assertTrue($f->validate('?'));
        $this->assertTrue($f->validate('01'));
        $this->assertFalse($f->validate('5W,L'));
        $this->assertFalse($f->validate('1.'));
    }

    public function testChecksIfSatisfied(): void
    {
        $f = new Day_Of_Month_Field();
        $this->assertTrue($f->is_satisfied_by(new DateTime(), '?', false));
        $this->assertTrue($f->is_satisfied_by(new DateTimeImmutable(), '?', false));
    }

    public function testIncrementsDate(): void
    {
        $d = new DateTime('2011-03-15 11:15:00');
        $f = new Day_Of_Month_Field();
        $f->increment($d);
        $this->assertSame('2011-03-16 00:00:00', $d->format('Y-m-d H:i:s'));

        $d = new DateTime('2011-03-15 11:15:00');
        $f->increment($d, true);
        $this->assertSame('2011-03-14 23:59:00', $d->format('Y-m-d H:i:s'));
    }

    public function testIncrementsDateTimeImmutable(): void
    {
        $d = new DateTimeImmutable('2011-03-15 11:15:00');
        $f = new Day_Of_Month_Field();
        $f->increment($d);
        $this->assertSame('2011-03-16 00:00:00', $d->format('Y-m-d H:i:s'));
    }

    /**
     * Day of the month cannot accept a 0 value, it must be between 1 and 31
     * See Github issue #120.
     *
     * @since 2017-01-22
     */
    public function testDoesNotAccept0Date(): void
    {
        $f = new Day_Of_Month_Field();
        $this->assertFalse($f->validate('0'));
    }

    public function testIncrementAcrossDstChangeBerlin(): void
    {
        $tz = new \DateTimeZone('Europe/Berlin');
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', '2021-03-28 01:59:00', $tz);
        $f = new Day_Of_Month_Field();
        $f->increment($d);
        $this->assertSame('2021-03-29 00:00:00', $d->format('Y-m-d H:i:s'));

        $f->increment($d, true);
        $this->assertSame('2021-03-28 23:59:00', $d->format('Y-m-d H:i:s'));
        $f->increment($d, true);
        $this->assertSame('2021-03-27 23:59:00', $d->format('Y-m-d H:i:s'));
    }

    public function testIncrementAcrossDstChangeLondon(): void
    {
        $tz = new \DateTimeZone('Europe/London');
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', '2021-03-28 00:59:00', $tz);
        $f = new Day_Of_Month_Field();
        $f->increment($d);
        $this->assertSame('2021-03-29 00:00:00', $d->format('Y-m-d H:i:s'));
        $f->increment($d);
        $this->assertSame('2021-03-30 00:00:00', $d->format('Y-m-d H:i:s'));

        $f->increment($d, true);
        $this->assertSame('2021-03-29 23:59:00', $d->format('Y-m-d H:i:s'));
        $f->increment($d, true);
        $this->assertSame('2021-03-28 23:59:00', $d->format('Y-m-d H:i:s'));
        $f->increment($d, true);
        $this->assertSame('2021-03-27 23:59:00', $d->format('Y-m-d H:i:s'));
    }

    public function testIssue151DOMFieldSupportLW()
    {
        $f = new Day_Of_Month_Field();
        $this->assertTrue($f->validate('LW'));
    }
}
