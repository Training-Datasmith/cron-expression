<?php

declare(strict_types=1);

namespace Cron\Tests;

use Cron\Day_Of_Week_Field;
use Cron\Hours_Field;
use Cron\Minutes_Field;
use Cron\Month_Field;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\TestCase;

/**
 * @author Michael Dowling <mtdowling@gmail.com>
 */
#[CoversFunction('is_range')]
#[CoversFunction('is_increments_of_ranges')]
#[CoversFunction('is_in_range')]
#[CoversFunction('is_in_increments_of_ranges')]
#[CoversFunction('is_satisfied')]
class Abstract_Field_Test extends TestCase
{
    public function testTestsIfRange(): void
    {
        $f = new Day_Of_Week_Field();
        $this->assertTrue($f->is_range('1-2'));
        $this->assertFalse($f->is_range('2'));
    }

    public function testTestsIfIncrementsOfRanges(): void
    {
        $f = new Day_Of_Week_Field();
        $this->assertFalse($f->is_increments_of_ranges('1-2'));
        $this->assertTrue($f->is_increments_of_ranges('1/2'));
        $this->assertTrue($f->is_increments_of_ranges('*/2'));
        $this->assertTrue($f->is_increments_of_ranges('3-12/2'));
    }

    public function testTestsIfInRange(): void
    {
        $f = new Day_Of_Week_Field();
        $this->assertTrue($f->is_in_range(1, '1-2'));
        $this->assertTrue($f->is_in_range(2, '1-2'));
        $this->assertTrue($f->is_in_range(5, '4-12'));
        $this->assertFalse($f->is_in_range(3, '4-12'));
        $this->assertFalse($f->is_in_range(13, '4-12'));
    }

    public function testTestsIfInIncrementsOfRangesOnZeroStartRange(): void
    {
        $f = new Minutes_Field();
        $this->assertTrue($f->is_in_increments_of_ranges(3, '3-59/2'));
        $this->assertTrue($f->is_in_increments_of_ranges(13, '3-59/2'));
        $this->assertTrue($f->is_in_increments_of_ranges(15, '3-59/2'));
        $this->assertTrue($f->is_in_increments_of_ranges(14, '*/2'));
        $this->assertFalse($f->is_in_increments_of_ranges(2, '3-59/13'));
        $this->assertFalse($f->is_in_increments_of_ranges(14, '*/13'));
        $this->assertFalse($f->is_in_increments_of_ranges(14, '3-59/2'));
        $this->assertFalse($f->is_in_increments_of_ranges(3, '2-59'));
        $this->assertFalse($f->is_in_increments_of_ranges(3, '2'));
        $this->assertFalse($f->is_in_increments_of_ranges(3, '*'));
        $this->assertFalse($f->is_in_increments_of_ranges(0, '*/0'));
        $this->assertFalse($f->is_in_increments_of_ranges(1, '*/0'));

        $this->assertTrue($f->is_in_increments_of_ranges(4, '4/1'));
        $this->assertFalse($f->is_in_increments_of_ranges(14, '4/1'));
        $this->assertFalse($f->is_in_increments_of_ranges(34, '4/1'));
    }

    public function testTestsIfInIncrementsOfRangesOnOneStartRange(): void
    {
        $f = new Month_Field();
        $this->assertTrue($f->is_in_increments_of_ranges(3, '3-12/2'));
        $this->assertFalse($f->is_in_increments_of_ranges(13, '3-12/2'));
        $this->assertFalse($f->is_in_increments_of_ranges(15, '3-12/2'));
        $this->assertTrue($f->is_in_increments_of_ranges(3, '*/2'));
        $this->assertFalse($f->is_in_increments_of_ranges(3, '*/3'));
        $this->assertTrue($f->is_in_increments_of_ranges(7, '*/3'));
        $this->assertFalse($f->is_in_increments_of_ranges(14, '3-12/2'));
        $this->assertFalse($f->is_in_increments_of_ranges(3, '2-12'));
        $this->assertFalse($f->is_in_increments_of_ranges(3, '2'));
        $this->assertFalse($f->is_in_increments_of_ranges(3, '*'));
        $this->assertFalse($f->is_in_increments_of_ranges(0, '*/0'));
        $this->assertFalse($f->is_in_increments_of_ranges(1, '*/0'));

        $this->assertTrue($f->is_in_increments_of_ranges(4, '4/1'));
        $this->assertFalse($f->is_in_increments_of_ranges(14, '4/1'));
        $this->assertFalse($f->is_in_increments_of_ranges(34, '4/1'));
    }

    public function testTestsIfSatisfied(): void
    {
        $f = new Day_Of_Week_Field();
        $this->assertTrue($f->is_satisfied(12, '3-13'));
        $this->assertFalse($f->is_satisfied(15, '3-7/2'));
        $this->assertTrue($f->is_satisfied(12, '*'));
        $this->assertTrue($f->is_satisfied(12, '12'));
        $this->assertFalse($f->is_satisfied(12, '3-11'));
        $this->assertFalse($f->is_satisfied(12, '3-7/2'));
        $this->assertFalse($f->is_satisfied(12, '11'));
    }

    /**
     * Allows ranges and lists to coexist in the same expression.
     *
     * @see https://github.com/dragonmantank/cron-expression/issues/5
     */
    public function testAllowRangesAndLists(): void
    {
        $expression = '5-7,11-13';
        $f = new Hours_Field();
        $this->assertTrue($f->validate($expression));
    }

    /**
     * Makes sure that various types of ranges expand out properly.
     *
     * @see https://github.com/dragonmantank/cron-expression/issues/5
     */
    public function testGetRangeForExpressionExpandsCorrectly(): void
    {
        $f = new Hours_Field();
        $this->assertSame([5, 6, 7, 11, 12, 13], $f->get_range_for_expression('5-7,11-13', 23));
        $this->assertSame(['5', '6', '7', '11', '12', '13'], $f->get_range_for_expression('5,6,7,11,12,13', 23));
        $this->assertSame([0, 6, 12, 18], $f->get_range_for_expression('*/6', 23));
        $this->assertSame([5, 11], $f->get_range_for_expression('5-13/6', 23));
        $this->assertSame([1, 2, 3, 4, 11, 13, 21, 24, 27, 40, 50], $f->get_range_for_expression('1-4,11-14/2,21-27/3,40-59/10', 59));
        $this->assertSame(['1', '3', 5, 6, 7, 11, 12, 13, 14, 15, 17, 19, 21, '23'], $f->get_range_for_expression('1,3,5-7,11-15/1,17-22/2,23', 23));
    }
}
