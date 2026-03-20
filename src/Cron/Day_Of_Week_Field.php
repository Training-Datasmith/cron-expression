<?php

declare (strict_types=1);
namespace Cron;

use DateTimeInterface;
use InvalidArgumentException;
/**
 * Day of week field.  Allows: * / , - ? L #.
 *
 * Days of the week can be represented as a number 0-7 (0|7 = Sunday)
 * or as a three letter string: SUN, MON, TUE, WED, THU, FRI, SAT.
 *
 * 'L' stands for "last". It allows you to specify constructs such as
 * "the last Friday" of a given month.
 *
 * '#' is allowed for the day-of-week field, and must be followed by a
 * number between one and five. It allows you to specify constructs such as
 * "the second Friday" of a given month.
 */
class Day_Of_Week_Field extends Abstract_Field
{
    /**
     * {@inheritdoc}
     */
    protected $range_start = 0;
    /**
     * {@inheritdoc}
     */
    protected $range_end = 7;
    /**
     * @var array<int, int> Weekday range
     */
    protected $nth_range;
    /**
     * {@inheritdoc}
     */
    protected $literals = [1 => 'MON', 2 => 'TUE', 3 => 'WED', 4 => 'THU', 5 => 'FRI', 6 => 'SAT', 7 => 'SUN'];
    /**
     * Constructor
     */
    public function __construct()
    {
        $this->nth_range = range(1, 5);
        parent::__construct();
    }
    /**
     * @inheritDoc
     */
    public function is_satisfied_by(DateTimeInterface $date, $value, bool $invert): bool
    {
        if ('?' === $value) {
            return true;
        }
        // Convert text day of the week values to integers
        $value = $this->convert_literals($value);
        $current_year = (int) $date->format('Y');
        $current_month = (int) $date->format('m');
        $last_day_of_month = (int) $date->format('t');
        // Find out if this is the last specific weekday of the month
        if ($l_position = strpos($value, 'L')) {
            $weekday = (int) $this->convert_literals(substr($value, 0, $l_position));
            $weekday %= 7;
            $days_in_month = (int) $date->format('t');
            $remaining_days_in_month = $days_in_month - (int) $date->format('d');
            return $weekday === (int) $date->format('w') && $remaining_days_in_month < 7;
        }
        // Handle # hash tokens
        if (strpos($value, '#')) {
            [$weekday, $nth] = explode('#', $value);
            if (!is_numeric($nth)) {
                throw new InvalidArgumentException("Hashed weekdays must be numeric, {$nth} given");
            }
            $nth = (int) $nth;
            // 0 and 7 are both Sunday, however 7 matches date('N') format ISO-8601
            if ('0' === $weekday) {
                $weekday = 7;
            }
            $weekday = (int) $this->convert_literals((string) $weekday);
            // Validate the hash fields
            if ($weekday < 0 || $weekday > 7) {
                throw new InvalidArgumentException("Weekday must be a value between 0 and 7. {$weekday} given");
            }
            if (!\in_array($nth, $this->nth_range, true)) {
                throw new InvalidArgumentException("There are never more than 5 or less than 1 of a given weekday in a month, {$nth} given");
            }
            // The current weekday must match the targeted weekday to proceed
            if ((int) $date->format('N') !== $weekday) {
                return false;
            }
            $tdate = clone $date;
            $tdate = $tdate->set_date($current_year, $current_month, 1);
            $day_count = 0;
            $current_day = 1;
            while ($current_day < $last_day_of_month + 1) {
                if ((int) $tdate->format('N') === $weekday) {
                    if (++$day_count >= $nth) {
                        break;
                    }
                }
                $tdate = $tdate->set_date($current_year, $current_month, ++$current_day);
            }
            return (int) $date->format('j') === $current_day;
        }
        // Handle day of the week values
        if (str_contains($value, '-')) {
            $parts = explode('-', $value);
            if ('7' === $parts[0]) {
                $parts[0] = 0;
            } elseif ('0' === $parts[1]) {
                $parts[1] = 7;
            }
            $value = implode('-', $parts);
        }
        // Test to see which Sunday to use -- 0 == 7 == Sunday
        $format = \in_array(7, array_map(fn($value) => (int) $value, str_split($value)), true) ? 'N' : 'w';
        $field_value = (int) $date->format($format);
        return $this->is_satisfied($field_value, $value);
    }
    /**
     * @inheritDoc
     */
    public function increment(DateTimeInterface &$date, $invert = false, $parts = null): Field_Interface
    {
        if (!$invert) {
            $date = $date->add(new \DateInterval('P1D'));
            $date = $date->set_time(0, 0);
        } else {
            $date = $date->sub(new \DateInterval('P1D'));
            $date = $date->set_time(23, 59);
        }
        return $this;
    }
    /**
     * {@inheritdoc}
     */
    public function validate(string $value): bool
    {
        $basic_checks = parent::validate($value);
        if (!$basic_checks) {
            if ('?' === $value) {
                return true;
            }
            // Handle the # value
            if (str_contains($value, '#')) {
                $chunks = explode('#', $value);
                $chunks[0] = $this->convert_literals($chunks[0]);
                if (parent::validate($chunks[0]) && is_numeric($chunks[1]) && \in_array((int) $chunks[1], $this->nth_range, true)) {
                    return true;
                }
            }
            if (preg_match('/^(.*)L$/', $value, $matches)) {
                return $this->validate($matches[1]);
            }
            return false;
        }
        return $basic_checks;
    }
}