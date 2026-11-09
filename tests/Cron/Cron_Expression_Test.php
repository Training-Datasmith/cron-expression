<?php

declare(strict_types=1);

namespace Cron\Tests;

use Cron\Cron_Expression;
use Cron\Month_Field;
use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Ticket;
use PHPUnit\Framework\TestCase;

/**
 * @author Michael Dowling <mtdowling@gmail.com>
 */
#[CoversFunction('get_next_run_date')]
#[CoversClass(\Cron\Day_Of_Month_Field::class)]
#[CoversClass(\Cron\Day_Of_Week_Field::class)]
#[CoversClass(\Cron\Minutes_Field::class)]
#[CoversClass(\Cron\Hours_Field::class)]
#[CoversClass(\Cron\Month_Field::class)]
#[CoversClass(\Cron\Cron_Expression::class)]
#[CoversFunction('get_run_date')]
#[CoversFunction('__construct')]
#[CoversFunction('get_expression')]
#[CoversFunction('set_expression')]
#[CoversFunction('__toString')]
#[CoversFunction('set_part')]
#[CoversFunction('is_due')]
#[CoversFunction('get_previous_run_date')]
#[CoversFunction('get_multiple_run_dates')]
#[CoversFunction('set_max_iteration_count')]
#[CoversFunction('is_valid_expression')]
class Cron_Expression_Test extends TestCase
{
    public function testConstructorRecognizesTemplates(): void
    {
        $this->assertSame('0 0 1 1 *', (new Cron_Expression('@annually'))->get_expression());
        $this->assertSame('0 0 1 1 *', (new Cron_Expression('@yearly'))->get_expression());
        $this->assertSame('0 0 * * 0', (new Cron_Expression('@weekly'))->get_expression());
        $this->assertSame('0 0 * * *', (new Cron_Expression('@daily'))->get_expression());
        $this->assertSame('0 0 * * *', (new Cron_Expression('@midnight'))->get_expression());
    }

    public function testParsesCronSchedule(): void
    {
        // '2010-09-10 12:00:00'
        $cron = new Cron_Expression('1 2-4 * 4,5,6 */3');
        $this->assertSame('1', $cron->get_expression(Cron_Expression::MINUTE));
        $this->assertSame('2-4', $cron->get_expression(Cron_Expression::HOUR));
        $this->assertSame('*', $cron->get_expression(Cron_Expression::DAY));
        $this->assertSame('4,5,6', $cron->get_expression(Cron_Expression::MONTH));
        $this->assertSame('*/3', $cron->get_expression(Cron_Expression::WEEKDAY));
        $this->assertSame('1 2-4 * 4,5,6 */3', $cron->get_expression());
        $this->assertSame('1 2-4 * 4,5,6 */3', (string) $cron);
        $this->assertNull($cron->get_expression('foo'));
    }

    public function testParsesCronScheduleThrowsAnException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid CRON field value A at position 0');
        new Cron_Expression('A 1 2 3 4');
    }

    #[DataProvider('scheduleWithDifferentSeparatorsProvider')]
    public function testParsesCronScheduleWithAnySpaceCharsAsSeparators(mixed $schedule, array $expected): void
    {
        $cron = new Cron_Expression($schedule);
        $this->assertSame($expected[0], $cron->get_expression(Cron_Expression::MINUTE));
        $this->assertSame($expected[1], $cron->get_expression(Cron_Expression::HOUR));
        $this->assertSame($expected[2], $cron->get_expression(Cron_Expression::DAY));
        $this->assertSame($expected[3], $cron->get_expression(Cron_Expression::MONTH));
        $this->assertSame($expected[4], $cron->get_expression(Cron_Expression::WEEKDAY));
    }

    /**
     * Data provider for testParsesCronScheduleWithAnySpaceCharsAsSeparators.
     *
     * @return array
     */
    public static function scheduleWithDifferentSeparatorsProvider(): array
    {
        return [
            ["*\t*\t*\t*\t*\t", ['*', '*', '*', '*', '*', '*']],
            ['*  *  *  *  *  ', ['*', '*', '*', '*', '*', '*']],
            ["* \t * \t * \t * \t * \t", ['*', '*', '*', '*', '*', '*']],
            ["*\t \t*\t \t*\t \t*\t \t*\t \t", ['*', '*', '*', '*', '*', '*']],
        ];
    }

    public function testInvalidCronsWillFail(): void
    {
        $this->expectException(InvalidArgumentException::class);
        // Only four values
        $cron = new Cron_Expression('* * * 1');
    }

    public function testInvalidPartsWillFail(): void
    {
        $this->expectException(InvalidArgumentException::class);
        // Only four values
        $cron = new Cron_Expression('* * * * *');
        $cron->set_part(1, 'abc');
    }

    /**
     * Data provider for cron schedule.
     *
     * @return array
     */
    public static function scheduleProvider(): array
    {
        return [
            ['*/2 */2 * * *', '2015-08-10 21:47:27', '2015-08-10 22:00:00', false],
            ['* * * * *', '2015-08-10 21:50:37', '2015-08-10 21:50:00', true],
            ['* 20,21,22 * * *', '2015-08-10 21:50:00', '2015-08-10 21:50:00', true],
            // Handles CSV values
            ['* 20,22 * * *', '2015-08-10 21:50:00', '2015-08-10 22:00:00', false],
            // CSV values can be complex
            ['7-9 * */9 * *', '2015-08-10 22:02:33', '2015-08-10 22:07:00', false],
            // 15th minute, of the second hour, every 15 days, in January, every Friday
            ['1 * * * 7', '2015-08-10 21:47:27', '2015-08-16 00:01:00', false],
            // Test with exact times
            ['47 21 * * *', strtotime('2015-08-10 21:47:30'), '2015-08-10 21:47:00', true],
            // Test Day of the week (issue #1)
            // According cron implementation, 0|7 = sunday, 1 => monday, etc
            ['* * * * 0', strtotime('2011-06-15 23:09:00'), '2011-06-19 00:00:00', false],
            ['* * * * 7', strtotime('2011-06-15 23:09:00'), '2011-06-19 00:00:00', false],
            ['* * * * 1', strtotime('2011-06-15 23:09:00'), '2011-06-20 00:00:00', false],
            // Should return the sunday date as 7 equals 0
            ['0 0 * * MON,SUN', strtotime('2011-06-15 23:09:00'), '2011-06-19 00:00:00', false],
            ['0 0 * * 1,7', strtotime('2011-06-15 23:09:00'), '2011-06-19 00:00:00', false],
            ['0 0 * * 0-4', strtotime('2011-06-15 23:09:00'), '2011-06-16 00:00:00', false],
            ['0 0 * * 7-4', strtotime('2011-06-15 23:09:00'), '2011-06-16 00:00:00', false],
            ['0 0 * * 4-7', strtotime('2011-06-15 23:09:00'), '2011-06-16 00:00:00', false],
            ['0 0 * * 7-3', strtotime('2011-06-15 23:09:00'), '2011-06-19 00:00:00', false],
            ['0 0 * * 3-7', strtotime('2011-06-15 23:09:00'), '2011-06-16 00:00:00', false],
            ['0 0 * * 3-7', strtotime('2011-06-18 23:09:00'), '2011-06-19 00:00:00', false],
            // Test lists of values and ranges (Abhoryo)
            ['0 0 * * 2-7', strtotime('2011-06-20 23:09:00'), '2011-06-21 00:00:00', false],
            ['0 0 * * 2-7', strtotime('2011-06-18 23:09:00'), '2011-06-19 00:00:00', false],
            ['0 0 * * 4-7', strtotime('2011-07-19 00:00:00'), '2011-07-21 00:00:00', false],
            // Test increments of ranges
            ['0-12/4 * * * *', strtotime('2011-06-20 12:04:00'), '2011-06-20 12:04:00', true],
            ['4-59/2 * * * *', strtotime('2011-06-20 12:04:00'), '2011-06-20 12:04:00', true],
            ['4-59/2 * * * *', strtotime('2011-06-20 12:06:00'), '2011-06-20 12:06:00', true],
            ['4-59/3 * * * *', strtotime('2011-06-20 12:06:00'), '2011-06-20 12:07:00', false],
            // Test Day of the Week and the Day of the Month (issue #1)
            ['0 0 1 1 0', strtotime('2011-06-15 23:09:00'), '2012-01-01 00:00:00', false],
            ['0 0 1 JAN 0', strtotime('2011-06-15 23:09:00'), '2012-01-01 00:00:00', false],
            ['0 0 1 * 0', strtotime('2011-06-15 23:09:00'), '2011-06-19 00:00:00', false],
            // Test the W day of the week modifier for day of the month field
            ['0 0 2W * *', strtotime('2011-07-01 00:00:00'), '2011-07-01 00:00:00', true],
            ['0 0 1W * *', strtotime('2011-05-01 00:00:00'), '2011-05-02 00:00:00', false],
            ['0 0 1W * *', strtotime('2011-07-01 00:00:00'), '2011-07-01 00:00:00', true],
            ['0 0 3W * *', strtotime('2011-07-01 00:00:00'), '2011-07-04 00:00:00', false],
            ['0 0 16W * *', strtotime('2011-07-01 00:00:00'), '2011-07-15 00:00:00', false],
            ['0 0 28W * *', strtotime('2011-07-01 00:00:00'), '2011-07-28 00:00:00', false],
            ['0 0 30W * *', strtotime('2011-07-01 00:00:00'), '2011-07-29 00:00:00', false],
            ['0 0 31W * *', strtotime('2011-07-01 00:00:00'), '2011-07-29 00:00:00', false],
            // Test the last weekday of a month
            ['* * * * 5L', strtotime('2011-07-01 00:00:00'), '2011-07-29 00:00:00', false],
            ['* * * * 6L', strtotime('2011-07-01 00:00:00'), '2011-07-30 00:00:00', false],
            ['* * * * 7L', strtotime('2011-07-01 00:00:00'), '2011-07-31 00:00:00', false],
            ['* * * * 1L', strtotime('2011-07-24 00:00:00'), '2011-07-25 00:00:00', false],
            ['* * * 1 5L', strtotime('2011-12-25 00:00:00'), '2012-01-27 00:00:00', false],
            // Test the hash symbol for the nth weekday of a given month
            ['* * * * 5#2', strtotime('2011-07-01 00:00:00'), '2011-07-08 00:00:00', false],
            ['* * * * 5#1', strtotime('2011-07-01 00:00:00'), '2011-07-01 00:00:00', true],
            ['* * * * 3#4', strtotime('2011-07-01 00:00:00'), '2011-07-27 00:00:00', false],

            // Issue #7, documented example failed
            ['3-59/15 6-12 */15 1 2-5', strtotime('2017-01-08 00:00:00'), '2017-01-10 06:03:00', false],

            // https://github.com/laravel/framework/commit/07d160ac3cc9764d5b429734ffce4fa311385403
            ['* * * * MON-FRI', strtotime('2017-01-08 00:00:00'), strtotime('2017-01-09 00:00:00'), false],
            ['* * * * TUE', strtotime('2017-01-08 00:00:00'), strtotime('2017-01-10 00:00:00'), false],

            // Issue #60, make sure that casing is less relevant for shortcuts, months, and days
            ['0 1 15 JUL mon,Wed,FRi', strtotime('2019-11-14 00:00:00'), strtotime('2020-07-01 01:00:00'), false],
            ['0 1 15 jul mon,Wed,FRi', strtotime('2019-11-14 00:00:00'), strtotime('2020-07-01 01:00:00'), false],
            ['@Weekly', strtotime('2019-11-14 00:00:00'), strtotime('2019-11-17 00:00:00'), false],
            ['@WEEKLY', strtotime('2019-11-14 00:00:00'), strtotime('2019-11-17 00:00:00'), false],
            ['@WeeklY', strtotime('2019-11-14 00:00:00'), strtotime('2019-11-17 00:00:00'), false],

            // Issue #76, DOW and DOM do not support ?
            ['0 12 * * ?', strtotime('2020-08-20 00:00:00'), strtotime('2020-08-20 12:00:00'), false],
            ['0 12 ? * *', strtotime('2020-08-20 00:00:00'), strtotime('2020-08-20 12:00:00'), false],
            ['0-59/59 10 * * *', strtotime('2021-08-25 10:00:00'), strtotime('2021-08-25 10:00:00'), true],
            ['0-59/59 10 * * *', strtotime('2021-08-25 09:00:00'), strtotime('2021-08-25 10:00:00'), false],
            ['0-59/59 10 * * *', strtotime('2021-08-25 10:01:00'), strtotime('2021-08-25 10:59:00'), false],
            ['0-59/65 10 * * *', strtotime('2021-08-25 10:01:00'), strtotime('2021-08-25 10:05:00'), false],
            ['41-59/24 5 * * *', strtotime('2021-08-25 10:00:00'), strtotime('2021-08-26 05:41:00'), false],
        ];
    }

    /**
     * @param mixed $schedule
     * @param mixed $relativeTime
     * @param mixed $nextRun
     * @param mixed $expected_due
     */
    #[DataProvider('scheduleProvider')]
    public function testDeterminesIfCronIsDue($schedule, $relativeTime, $nextRun, $expected_due): void
    {
        // Test next run date
        $cron = new Cron_Expression($schedule);
        if (\is_string($relativeTime)) {
            $relativeTime = new DateTime($relativeTime);
        } elseif (\is_int($relativeTime)) {
            $relativeTime = date('Y-m-d H:i:s', $relativeTime);
        }

        $nextRunDate = new DateTime();
        if (\is_string($nextRun)) {
            $nextRunDate = new DateTime($nextRun);
        } elseif (\is_int($nextRun)) {
            $nextRunDate = new DateTime();
            $nextRunDate->setTimestamp($nextRun);
        }
        $this->assertSame($expected_due, $cron->is_due($relativeTime));
        $next = $cron->get_next_run_date($relativeTime, 0, true);

        $this->assertEquals($nextRunDate, $next);
    }

    public function testIsDueHandlesDifferentDates(): void
    {
        $cron = new Cron_Expression('* * * * *');
        $this->assertTrue($cron->is_due());
        $this->assertTrue($cron->is_due('now'));
        $this->assertTrue($cron->is_due(new DateTime('now')));
        $this->assertTrue($cron->is_due(date('Y-m-d H:i')));
        $this->assertTrue($cron->is_due(new DateTimeImmutable('now')));
    }

    public function testIsDueHandlesDifferentDefaultTimezones(): void
    {
        $originalTimezone = date_default_timezone_get();
        $cron = new Cron_Expression('0 15 * * 3'); //Wednesday at 15:00
        $date = '2014-01-01 15:00'; //Wednesday

        date_default_timezone_set('UTC');
        $this->assertTrue($cron->is_due(new DateTime($date), 'UTC'));
        $this->assertFalse($cron->is_due(new DateTime($date), 'Europe/Amsterdam'));
        $this->assertFalse($cron->is_due(new DateTime($date), 'Asia/Tokyo'));

        date_default_timezone_set('Europe/Amsterdam');
        $this->assertFalse($cron->is_due(new DateTime($date), 'UTC'));
        $this->assertTrue($cron->is_due(new DateTime($date), 'Europe/Amsterdam'));
        $this->assertFalse($cron->is_due(new DateTime($date), 'Asia/Tokyo'));

        date_default_timezone_set('Asia/Tokyo');
        $this->assertFalse($cron->is_due(new DateTime($date), 'UTC'));
        $this->assertFalse($cron->is_due(new DateTime($date), 'Europe/Amsterdam'));
        $this->assertTrue($cron->is_due(new DateTime($date), 'Asia/Tokyo'));

        date_default_timezone_set($originalTimezone);
    }

    public function testIsDueHandlesDifferentSuppliedTimezones(): void
    {
        $cron = new Cron_Expression('0 15 * * 3'); //Wednesday at 15:00
        $date = '2014-01-01 15:00'; //Wednesday

        $this->assertTrue($cron->is_due(new DateTime($date, new DateTimeZone('UTC')), 'UTC'));
        $this->assertFalse($cron->is_due(new DateTime($date, new DateTimeZone('UTC')), 'Europe/Amsterdam'));
        $this->assertFalse($cron->is_due(new DateTime($date, new DateTimeZone('UTC')), 'Asia/Tokyo'));

        $this->assertFalse($cron->is_due(new DateTime($date, new DateTimeZone('Europe/Amsterdam')), 'UTC'));
        $this->assertTrue($cron->is_due(new DateTime($date, new DateTimeZone('Europe/Amsterdam')), 'Europe/Amsterdam'));
        $this->assertFalse($cron->is_due(new DateTime($date, new DateTimeZone('Europe/Amsterdam')), 'Asia/Tokyo'));

        $this->assertFalse($cron->is_due(new DateTime($date, new DateTimeZone('Asia/Tokyo')), 'UTC'));
        $this->assertFalse($cron->is_due(new DateTime($date, new DateTimeZone('Asia/Tokyo')), 'Europe/Amsterdam'));
        $this->assertTrue($cron->is_due(new DateTime($date, new DateTimeZone('Asia/Tokyo')), 'Asia/Tokyo'));
    }

    public function testIsDueHandlesDifferentTimezonesAsArgument(): void
    {
        $cron = new Cron_Expression('0 15 * * 3'); //Wednesday at 15:00
        $date = '2014-01-01 15:00'; //Wednesday
        $utc = new \DateTimeZone('UTC');
        $amsterdam = new \DateTimeZone('Europe/Amsterdam');
        $tokyo = new \DateTimeZone('Asia/Tokyo');
        $this->assertTrue($cron->is_due(new DateTime($date, $utc), 'UTC'));
        $this->assertFalse($cron->is_due(new DateTime($date, $amsterdam), 'UTC'));
        $this->assertFalse($cron->is_due(new DateTime($date, $tokyo), 'UTC'));
        $this->assertFalse($cron->is_due(new DateTime($date, $utc), 'Europe/Amsterdam'));
        $this->assertTrue($cron->is_due(new DateTime($date, $amsterdam), 'Europe/Amsterdam'));
        $this->assertFalse($cron->is_due(new DateTime($date, $tokyo), 'Europe/Amsterdam'));
        $this->assertFalse($cron->is_due(new DateTime($date, $utc), 'Asia/Tokyo'));
        $this->assertFalse($cron->is_due(new DateTime($date, $amsterdam), 'Asia/Tokyo'));
        $this->assertTrue($cron->is_due(new DateTime($date, $tokyo), 'Asia/Tokyo'));
    }

    public function testRecognisesTimezonesAsPartOfDateTime(): void
    {
        $cron = new Cron_Expression('0 7 * * *');
        $tzCron = 'America/New_York';
        $tzServer = new \DateTimeZone('Europe/London');

        $dtCurrent = \DateTime::createFromFormat('!Y-m-d H:i:s', '2017-10-17 10:00:00', $tzServer);
        if (!$dtCurrent instanceof \DateTime) {
            throw new InvalidArgumentException('invalid current date time');
        }

        $dtPrev = $cron->get_previous_run_date($dtCurrent, 0, true, $tzCron);
        $this->assertEquals('1508151600 : 2017-10-16T07:00:00-04:00 : America/New_York', $dtPrev->format('U \\: c \\: e'));

        $dtCurrent = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', '2017-10-17 10:00:00', $tzServer);
        if (!$dtCurrent instanceof \DateTimeImmutable) {
            throw new InvalidArgumentException('invalid current date time immutable');
        }
        $dtPrev = $cron->get_previous_run_date($dtCurrent, 0, true, $tzCron);
        $this->assertEquals('1508151600 : 2017-10-16T07:00:00-04:00 : America/New_York', $dtPrev->format('U \\: c \\: e'));

        $dtCurrent = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', '2017-10-17 10:00:00', $tzServer);
        if (!$dtCurrent instanceof \DateTimeImmutable) {
            throw new InvalidArgumentException('invalid current date time immutable');
        }
        $dtPrev = $cron->get_previous_run_date($dtCurrent->format('c'), 0, true, $tzCron);
        $this->assertEquals('1508151600 : 2017-10-16T07:00:00-04:00 : America/New_York', $dtPrev->format('U \\: c \\: e'));

        $dtCurrent = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', '2017-10-17 10:00:00', $tzServer);
        if (!$dtCurrent instanceof \DateTimeImmutable) {
            throw new InvalidArgumentException('invalid current date time immutable');
        }
        $dtPrev = $cron->get_previous_run_date($dtCurrent->format('\\@U'), 0, true, $tzCron);
        $this->assertEquals('1508151600 : 2017-10-16T07:00:00-04:00 : America/New_York', $dtPrev->format('U \\: c \\: e'));
    }

    public function testCanGetPreviousRunDates(): void
    {
        $cron = new Cron_Expression('* * * * *');
        $next = $cron->get_next_run_date('now');
        $two = $cron->get_next_run_date('now', 1);
        $this->assertEquals($next, $cron->get_previous_run_date($two));

        $cron = new Cron_Expression('* */2 * * *');
        $next = $cron->get_next_run_date('now');
        $two = $cron->get_next_run_date('now', 1);
        $this->assertEquals($next, $cron->get_previous_run_date($two));

        $cron = new Cron_Expression('* * * */2 *');
        $next = $cron->get_next_run_date('now');
        $two = $cron->get_next_run_date('now', 1);
        $this->assertEquals($next, $cron->get_previous_run_date($two));
    }

    public function testProvidesMultipleRunDates(): void
    {
        $cron = new Cron_Expression('*/2 * * * *');
        $this->assertEquals([
            new DateTime('2008-11-09 00:00:00'),
            new DateTime('2008-11-09 00:02:00'),
            new DateTime('2008-11-09 00:04:00'),
            new DateTime('2008-11-09 00:06:00'),
        ], $cron->get_multiple_run_dates(4, '2008-11-09 00:00:00', false, true));
    }

    public function testProvidesMultipleRunDatesForTheFarFuture(): void
    {
        // Fails with the default 1000 iteration limit
        $cron = new Cron_Expression('0 0 12 1 *');
        $cron->set_max_iteration_count(2000);
        $this->assertEquals([
            new DateTime('2016-01-12 00:00:00'),
            new DateTime('2017-01-12 00:00:00'),
            new DateTime('2018-01-12 00:00:00'),
            new DateTime('2019-01-12 00:00:00'),
            new DateTime('2020-01-12 00:00:00'),
            new DateTime('2021-01-12 00:00:00'),
            new DateTime('2022-01-12 00:00:00'),
            new DateTime('2023-01-12 00:00:00'),
            new DateTime('2024-01-12 00:00:00'),
        ], $cron->get_multiple_run_dates(9, '2015-04-28 00:00:00', false, true));
    }

    public function testCanIterateOverNextRuns(): void
    {
        $cron = new Cron_Expression('@weekly');
        $nextRun = $cron->get_next_run_date('2008-11-09 08:00:00');
        $this->assertEquals($nextRun, new DateTime('2008-11-16 00:00:00'));

        // You can iterate over them
        $nextRun = $cron->get_next_run_date($cron->get_next_run_date('2008-11-09 00:00:00', 1, true), 1, true);
        $this->assertEquals($nextRun, new DateTime('2008-11-23 00:00:00'));

        // You can skip more than one
        $nextRun = $cron->get_next_run_date('2008-11-09 00:00:00', 2, true);
        $this->assertEquals($nextRun, new DateTime('2008-11-23 00:00:00'));
        $nextRun = $cron->get_next_run_date('2008-11-09 00:00:00', 3, true);
        $this->assertEquals($nextRun, new DateTime('2008-11-30 00:00:00'));
    }

    public function testGetRunDateHandlesDifferentDates(): void
    {
        $cron = new Cron_Expression('@weekly');
        $date = new DateTime('2019-03-10 00:00:00');
        $this->assertEquals($date, $cron->get_next_run_date('2019-03-03 08:00:00'));
        $this->assertEquals($date, $cron->get_next_run_date(new DateTime('2019-03-03 08:00:00')));
        $this->assertEquals($date, $cron->get_next_run_date(new DateTimeImmutable('2019-03-03 08:00:00')));
    }

    /**
     * If both day of month and day of week are set in an expression,
     * we have to return a date which among dates matching either of two criteria is closest to the current date.
     *
     * Previously the earliest of dates was always returned, which was incorrect for previous run date.
     *
     */
    public function testGetRunDateHandlesSimultaneousDayOfMonthAndDayOfWeek(): void
    {
        $cron = new Cron_Expression('0 0 13 * 3');
        $date = new DateTime('2021-07-15 00:00:00');
        $this->assertEquals(new DateTime('2021-07-21 00:00:00'), $cron->get_next_run_date($date));
        $this->assertEquals(new DateTime('2021-07-14 00:00:00'), $cron->get_previous_run_date($date));
    }

    public function testSkipsCurrentDateByDefault(): void
    {
        $cron = new Cron_Expression('* * * * *');
        $current = new DateTime('now');
        $next = $cron->get_next_run_date($current);
        $nextPrev = $cron->get_previous_run_date($next);
        $this->assertSame($current->format('Y-m-d H:i:00'), $nextPrev->format('Y-m-d H:i:s'));
    }

    #[Ticket('7')]
    public function testStripsForSeconds(): void
    {
        $cron = new Cron_Expression('* * * * *');
        $current = new DateTime('2011-09-27 10:10:54');
        $this->assertSame('2011-09-27 10:11:00', $cron->get_next_run_date($current)->format('Y-m-d H:i:s'));
    }

    public function testFixesPhpBugInDateIntervalMonth(): void
    {
        $cron = new Cron_Expression('0 0 27 JAN *');
        $this->assertSame('2011-01-27 00:00:00', $cron->get_previous_run_date('2011-08-22 00:00:00')->format('Y-m-d H:i:s'));
    }

    public function testIssue29(): void
    {
        $cron = new Cron_Expression('@weekly');
        $this->assertSame(
            '2013-03-10 00:00:00',
            $cron->get_previous_run_date('2013-03-17 00:00:00')->format('Y-m-d H:i:s')
        );
    }

    /**
     * @see https://github.com/mtdowling/cron-expression/issues/20
     */
    public function testIssue20(): void
    {
        $e = new Cron_Expression('* * * * MON#1');
        $this->assertTrue($e->is_due(new DateTime('2014-04-07 00:00:00')));
        $this->assertFalse($e->is_due(new DateTime('2014-04-14 00:00:00')));
        $this->assertFalse($e->is_due(new DateTime('2014-04-21 00:00:00')));

        $e->set_expression('* * * * SAT#2');
        $this->assertFalse($e->is_due(new DateTime('2014-04-05 00:00:00')));
        $this->assertTrue($e->is_due(new DateTime('2014-04-12 00:00:00')));
        $this->assertFalse($e->is_due(new DateTime('2014-04-19 00:00:00')));

        $e->set_expression('* * * * SUN#3');
        $this->assertFalse($e->is_due(new DateTime('2014-04-13 00:00:00')));
        $this->assertTrue($e->is_due(new DateTime('2014-04-20 00:00:00')));
        $this->assertFalse($e->is_due(new DateTime('2014-04-27 00:00:00')));
    }

    public function testKeepOriginalTime(): void
    {
        $now = new \DateTime();
        $strNow = $now->format(DateTime::ISO8601);
        $cron = new Cron_Expression('0 0 * * *');
        $cron->get_previous_run_date($now);
        $this->assertSame($strNow, $now->format(DateTime::ISO8601));
    }

    public function testValidationWorks(): void
    {
        // Invalid. Only four values
        $this->assertFalse(Cron_Expression::is_valid_expression('* * * 1'));
        // Valid
        $this->assertTrue(Cron_Expression::is_valid_expression('* * * * 1'));

        // Issue #156, 13 is an invalid month
        $this->assertFalse(Cron_Expression::is_valid_expression('* * * 13 * '));

        // Issue #155, 90 is an invalid second
        $this->assertFalse(Cron_Expression::is_valid_expression('90 * * * *'));

        // Issue #154, 24 is an invalid hour
        $this->assertFalse(Cron_Expression::is_valid_expression('0 24 1 12 0'));

        // Issue #125, this is just all sorts of wrong
        $this->assertFalse(Cron_Expression::is_valid_expression('990 14 * * mon-fri0345345'));

        // Issue #137, multiple question marks are not allowed
        $this->assertFalse(Cron_Expression::is_valid_expression('0 8 ? * ?'));
        // Question marks are only allowed in dom and dow part
        $this->assertFalse(Cron_Expression::is_valid_expression('? * * * *'));
        $this->assertFalse(Cron_Expression::is_valid_expression('* ? * * *'));
        $this->assertFalse(Cron_Expression::is_valid_expression('* * * ? *'));

        // see https://github.com/dragonmantank/cron-expression/issues/5
        $this->assertTrue(Cron_Expression::is_valid_expression('2,17,35,47 5-7,11-13 * * *'));
    }

    /**
     * Makes sure that 00 is considered a valid value for 0-based fields
     * cronie allows numbers with a leading 0, so adding support for this as well.
     *
     * @see https://github.com/dragonmantank/cron-expression/issues/12
     */
    public function testDoubleZeroIsValid(): void
    {
        $this->assertTrue(Cron_Expression::is_valid_expression('00 * * * *'));
        $this->assertTrue(Cron_Expression::is_valid_expression('01 * * * *'));
        $this->assertTrue(Cron_Expression::is_valid_expression('* 00 * * *'));
        $this->assertTrue(Cron_Expression::is_valid_expression('* 01 * * *'));

        $e = new Cron_Expression('00 * * * *');
        $this->assertTrue($e->is_due(new DateTime('2014-04-07 00:00:00')));
        $e->set_expression('01 * * * *');
        $this->assertTrue($e->is_due(new DateTime('2014-04-07 00:01:00')));

        $e->set_expression('* 00 * * *');
        $this->assertTrue($e->is_due(new DateTime('2014-04-07 00:00:00')));
        $e->set_expression('* 01 * * *');
        $this->assertTrue($e->is_due(new DateTime('2014-04-07 01:00:00')));
    }

    /**
     * Ranges with large steps should "wrap around" to the appropriate value
     * cronie allows for steps that are larger than the range of a field, with it wrapping around like a ring buffer. We
     * should do the same.
     *
     * @see https://github.com/dragonmantank/cron-expression/issues/6
     */
    public function testRangesWrapAroundWithLargeSteps(): void
    {
        $f = new Month_Field();
        $this->assertTrue($f->validate('*/123'));
        $this->assertSame([4], $f->get_range_for_expression('*/123', 12));

        $e = new Cron_Expression('* * * */123 *');
        $this->assertTrue($e->is_due(new DateTime('2014-04-07 00:00:00')));

        $nextRunDate = $e->get_next_run_date(new DateTime('2014-04-07 00:00:00'));
        $this->assertSame('2014-04-07 00:01:00', $nextRunDate->format('Y-m-d H:i:s'));

        $nextRunDate = $e->get_next_run_date(new DateTime('2014-05-07 00:00:00'));
        $this->assertSame('2015-04-01 00:00:00', $nextRunDate->format('Y-m-d H:i:s'));
    }

    /**
     * When there is an issue with a field, we should report the human readable position.
     *
     * @see https://github.com/dragonmantank/cron-expression/issues/29
     */
    public function testFieldPositionIsHumanAdjusted(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('6 is not a valid position');

        $e = new Cron_Expression('0 * * * * ? *');
    }

    /**
     * @see https://github.com/dragonmantank/cron-expression/issues/35
     */
    public function testMakeDayOfWeekAnOrSometimes(): void
    {
        $cron = new Cron_Expression('30 0 1 * 1');
        $runs = $cron->get_multiple_run_dates(5, date('2019-10-10 23:20:00'), false, true);

        $this->assertSame('2019-10-14 00:30:00', $runs[0]->format('Y-m-d H:i:s'));
        $this->assertSame('2019-10-21 00:30:00', $runs[1]->format('Y-m-d H:i:s'));
        $this->assertSame('2019-10-28 00:30:00', $runs[2]->format('Y-m-d H:i:s'));
        $this->assertSame('2019-11-01 00:30:00', $runs[3]->format('Y-m-d H:i:s'));
        $this->assertSame('2019-11-04 00:30:00', $runs[4]->format('Y-m-d H:i:s'));
    }

    /**
     * Make sure that get_next_run_date() does not add arbitrary minutes
     *
     * @see https://github.com/mtdowling/cron-expression/issues/152
     */
    public function testNextRunDateShouldNotAddMinutes(): void
    {
        $e = new Cron_Expression('* 19 * * *');
        $tz = new \DateTimeZone('Europe/London');
        $dt = new \DateTimeImmutable('2021-05-31 18:15:00', $tz);
        $nextRunDate = $e->get_next_run_date($dt);

        $this->assertSame('00', $nextRunDate->format('i'));
    }

    /**
     * Tests the get_parts function.
     */
    public function testGetParts(): void
    {
        $e = Cron_Expression::factory('0 22 * * 1-5');
        $parts = $e->get_parts();

        $this->assertSame('0', $parts[0]);
        $this->assertSame('22', $parts[1]);
        $this->assertSame('*', $parts[2]);
        $this->assertSame('*', $parts[3]);
        $this->assertSame('1-5', $parts[4]);
    }

    public function testBerlinShouldAdvanceProperlyOverDST()
    {
        $e = new Cron_Expression('0 0 1 * *');
        $expected = new \DateTime('2022-11-01 00:00:00', new \DateTimeZone('Europe/Berlin'));
        $next = $e->get_next_run_date(new \DateTime('2022-10-30', new \DateTimeZone('Europe/Berlin')));
        $this->assertEquals($expected, $next);
    }

    /**
     * Helps validate additional test cases that were failing as part of #131's fix
     *
     * @see https://github.com/dragonmantank/cron-expression/issues/131
     */
    public function testIssue131()
    {
        $e = new Cron_Expression('* * * * 2');
        $expected = new \DateTime('2020-10-27 00:00:00', new \DateTimeZone('Europe/Berlin'));
        $next = $e->get_next_run_date(new DateTime('2020-10-23 15:31:45', new \DateTimeZone('Europe/Berlin')));
        $this->assertEquals($expected, $next);

        $expected = new \DateTime('2020-10-20 23:59:00', new \DateTimeZone('Europe/Berlin'));
        $prev = $e->get_previous_run_date(new DateTime('2020-10-23 15:31:45', new \DateTimeZone('Europe/Berlin')));
        $this->assertEquals($expected, $prev);

        $e = new Cron_Expression('15 1 1 9,11 *');
        $expected = new \DateTime('2022-09-01 01:15:00');
        $next = $e->get_next_run_date(new \DateTime('2022-08-20 03:44:02'));
        $this->assertEquals($expected, $next);

        $expected = new \DateTime('2021-11-01 01:15:00');
        $prev = $e->get_previous_run_date(new \DateTime('2022-08-20 03:44:02'));
        $this->assertEquals($expected, $prev);
    }

    public function testIssue128()
    {
        $e = new Cron_Expression('0 20 L 6,12 ?');
        $expected = new \DateTime('2022-12-31 20:00:00');
        $next = $e->get_next_run_date(new \DateTime('2022-08-20 03:44:02'));
        $this->assertEquals($expected, $next);

        $expected = new \DateTime('2023-12-31 20:00:00');
        $next = $e->get_next_run_date(new \DateTime('2022-08-20 03:44:02'), 2);
        $this->assertEquals($expected, $next);

        $expected = new \DateTime('2022-06-30 20:00:00');
        $prev = $e->get_previous_run_date(new \DateTime('2022-08-20 03:44:02'));
        $this->assertEquals($expected, $prev);

        $expected = new \DateTime('2021-12-31 20:00:00');
        $prev = $e->get_previous_run_date(new \DateTime('2022-08-20 03:44:02'), 1);
        $this->assertEquals($expected, $prev);

        $e = new Cron_Expression('0 20 L 6,12 0-6');
        $expected = new \DateTime('2022-12-01 20:00:00');
        $next = $e->get_next_run_date(new \DateTime('2022-08-20 03:44:02'));
        $this->assertEquals($expected, $next);

        $e = new Cron_Expression('0 20 L 6,12 0-6');
        $expected = new \DateTime('2022-12-02 20:00:00');
        $next = $e->get_next_run_date(new \DateTime('2022-08-20 03:44:02'), 1);
        $this->assertEquals($expected, $next);

        $e = new Cron_Expression('0 20 L 6,12 0-6');
        $expected = new \DateTime('2022-12-03 20:00:00');
        $next = $e->get_next_run_date(new \DateTime('2022-08-20 03:44:02'), 2);
        $this->assertEquals($expected, $next);

        $e = new Cron_Expression('0 20 * 6,12 *');
        $expected = new \DateTime('2022-12-01 20:00:00');
        $next = $e->get_next_run_date(new \DateTime('2022-08-20 03:44:02'));
        $this->assertEquals($expected, $next);

        $e = new Cron_Expression('0 20 * 6,12 *');
        $expected = new \DateTime('2022-12-06 20:00:00');
        $next = $e->get_next_run_date(new \DateTime('2022-08-20 03:44:02'), 5);
        $this->assertEquals($expected, $next);
    }

    public function testItCanRegisterAnValidExpression(): void
    {
        Cron_Expression::register_alias('@every', '* * * * *');

        self::assertCount(8, Cron_Expression::get_aliases());
        self::assertArrayHasKey('@every', Cron_Expression::get_aliases());
        self::assertTrue(Cron_Expression::supports_alias('@every'));
        self::assertEquals(new Cron_Expression('@every'), new Cron_Expression('* * * * *'));

        self::assertTrue(Cron_Expression::unregister_alias('@every'));
        self::assertFalse(Cron_Expression::unregister_alias('@every'));

        self::assertCount(7, Cron_Expression::get_aliases());
        self::assertArrayNotHasKey('@every', Cron_Expression::get_aliases());
        self::assertFalse(Cron_Expression::supports_alias('@every'));

        $this->expectException(LogicException::class);
        new Cron_Expression('@every');
    }

    public function testItWillFailToRegisterAnInvalidExpression(): void
    {
        $this->expectException(LogicException::class);

        Cron_Expression::register_alias('@every', 'foobar');
    }

    public function testItWillFailToRegisterAnInvalidName(): void
    {
        $this->expectException(LogicException::class);

        Cron_Expression::register_alias('every', '* * * * *');
    }

    public function testItWillFailToRegisterAnInvalidName2(): void
    {
        $this->expectException(LogicException::class);

        Cron_Expression::register_alias('@évery', '* * * * *');
    }

    public function testItWillFailToRegisterAValidNameTwice(): void
    {
        Cron_Expression::register_alias('@Ev_eR_y', '* * * * *');

        $this->expectException(LogicException::class);
        Cron_Expression::register_alias('@eV_Er_Y', '2 2 2 2 2');
    }

    public function testItWillFailToUnregisterADefaultExpression(): void
    {
        $this->expectException(LogicException::class);

        Cron_Expression::unregister_alias('@daily');
    }

    public function testIssue134ForeachInvalidArgumentOnHours()
    {
        $cron = new Cron_Expression('0 0 1 1 *');
        $prev = $cron->get_previous_run_date(new \DateTimeImmutable('2021-09-07T09:36:00Z'));
        $this->assertEquals(new \DateTime('2021-01-01 00:00:00'), $prev);
    }

    public function testIssue151ExpressionSupportLW()
    {
        $cron = new Cron_Expression('0 10 LW * *');
        $this->assertTrue($cron->is_due(new \DateTimeImmutable('2023-08-31 10:00:00')));
        $this->assertFalse($cron->is_due(new \DateTimeImmutable('2023-08-30 10:00:00')));
    }
}
