<?php

declare (strict_types=1);
namespace Cron;

use DateTimeInterface;
/**
 * Abstract CRON expression field.
 */
abstract class Abstract_Field implements Field_Interface
{
    /**
     * Full range of values that are allowed for this field type.
     *
     * @var array<int, int>
     */
    protected array $full_range;
    /**
     * Literal values we need to convert to integers.
     *
     * @var array<int, string>
     */
    protected $literals = [];
    /**
     * Start value of the full range.
     *
     * @var int
     */
    protected $range_start;
    /**
     * End value of the full range.
     *
     * @var int
     */
    protected $range_end;
    /**
     * Constructor
     */
    public function __construct()
    {
        $this->full_range = range($this->range_start, $this->range_end);
    }
    /**
     * Check to see if a field is satisfied by a value.
     *
     * @internal
     * @param int $dateValue Date value to check
     * @param string $value Value to test
     */
    public function is_satisfied(int $date_value, string $value): bool
    {
        if ($this->is_increments_of_ranges($value)) {
            return $this->is_in_increments_of_ranges($date_value, $value);
        }
        if ($this->is_range($value)) {
            return $this->is_in_range($date_value, $value);
        }
        return '*' === $value || $date_value === (int) $value;
    }
    /**
     * Check if a value is a range.
     *
     * @internal
     * @param string $value Value to test
     */
    public function is_range(string $value): bool
    {
        return str_contains($value, '-');
    }
    /**
     * Check if a value is an increments of ranges.
     *
     * @internal
     * @param string $value Value to test
     */
    public function is_increments_of_ranges(string $value): bool
    {
        return str_contains($value, '/');
    }
    /**
     * Test if a value is within a range.
     *
     * @internal
     * @param int $dateValue Set date value
     * @param string $value Value to test
     */
    public function is_in_range(int $date_value, $value): bool
    {
        $parts = array_map(function ($value): string {
            $value = trim((string) $value);
            return $this->convert_literals($value);
        }, explode('-', $value, 2));
        return $date_value >= $parts[0] && $date_value <= $parts[1];
    }
    /**
     * Test if a value is within an increments of ranges (offset[-to]/step size).
     *
     * @internal
     * @param int $dateValue Set date value
     * @param string $value Value to test
     */
    public function is_in_increments_of_ranges(int $date_value, string $value): bool
    {
        $chunks = array_map(trim(...), explode('/', $value, 2));
        $range = $chunks[0];
        $step = $chunks[1] ?? 0;
        // No step or 0 steps aren't cool
        if (null === $step || '0' === $step || 0 === $step) {
            return false;
        }
        // Expand the * to a full range
        if ('*' === $range) {
            $range = $this->range_start . '-' . $this->range_end;
        }
        // Generate the requested small range
        $range_chunks = explode('-', $range, 2);
        $range_start = (int) $range_chunks[0];
        $range_end = $range_chunks[1] ?? $range_start;
        $range_end = (int) $range_end;
        if ($range_start < $this->range_start || $range_start > $this->range_end || $range_start > $range_end) {
            throw new \OutOfRangeException('Invalid range start requested');
        }
        if ($range_end < $this->range_start || $range_end > $this->range_end || $range_end < $range_start) {
            throw new \OutOfRangeException('Invalid range end requested');
        }
        // Steps larger than the range need to wrap around and be handled
        // slightly differently than smaller steps
        // UPDATE - This is actually false. The C implementation will allow a
        // larger step as valid syntax, it never wraps around. It will stop
        // once it hits the end. Unfortunately this means in future versions
        // we will not wrap around. However, because the logic exists today
        // per the above documentation, fixing the bug from #89
        if ($step > $this->range_end) {
            $this_range = [$this->full_range[(int) $step % \count($this->full_range)]];
        } else if ($step > $range_end - $range_start) {
            $this_range[$range_start] = $range_start;
        } else {
            $this_range = range($range_start, $range_end, (int) $step);
        }
        return \in_array($date_value, $this_range, true);
    }
    /**
     * Returns a range of values for the given cron expression.
     *
     * @param string $expression The expression to evaluate
     * @param int $max Maximum offset for range
     *
     * @return array<int, int>
     */
    public function get_range_for_expression(string $expression, int $max): array
    {
        $values = [];
        $expression = $this->convert_literals($expression);
        if (str_contains($expression, ',')) {
            $ranges = explode(',', $expression);
            $values = [];
            foreach ($ranges as $range) {
                $expanded = $this->get_range_for_expression($range, $this->range_end);
                $values = array_merge($values, $expanded);
            }
            return $values;
        }
        if ($this->is_range($expression) || $this->is_increments_of_ranges($expression)) {
            if (!$this->is_increments_of_ranges($expression)) {
                [$offset, $to] = explode('-', $expression);
                $offset = $this->convert_literals($offset);
                $to = $this->convert_literals($to);
                $step_size = 1;
            } else {
                $range = array_map(trim(...), explode('/', $expression, 2));
                $step_size = $range[1] ?? 0;
                $range = $range[0];
                $range = explode('-', $range, 2);
                $offset = $range[0];
                $to = $range[1] ?? $max;
            }
            $offset = '*' === $offset ? $this->range_start : $offset;
            if ($step_size >= $this->range_end) {
                $values = [$this->full_range[(int) $step_size % \count($this->full_range)]];
            } else {
                for ($i = $offset; $i <= $to; $i += $step_size) {
                    $values[] = (int) $i;
                }
            }
            sort($values);
        } else {
            $values = [$expression];
        }
        return $values;
    }
    /**
     * Convert literal.
     *
     *
     */
    protected function convert_literals(string $value): string
    {
        if (\count($this->literals)) {
            $key = array_search(strtoupper($value), $this->literals, true);
            if (false !== $key) {
                return (string) $key;
            }
        }
        return $value;
    }
    /**
     * Checks to see if a value is valid for the field.
     *
     *
     */
    public function validate(string $value): bool
    {
        $value = $this->convert_literals($value);
        // All fields allow * as a valid value
        if ('*' === $value) {
            return true;
        }
        // Validate each chunk of a list individually
        if (str_contains($value, ',')) {
            foreach (explode(',', $value) as $list_item) {
                if (!$this->validate($list_item)) {
                    return false;
                }
            }
            return true;
        }
        if (str_contains($value, '/')) {
            [$range, $step] = explode('/', $value);
            // Don't allow numeric ranges
            if (is_numeric($range)) {
                return false;
            }
            return $this->validate($range) && filter_var($step, FILTER_VALIDATE_INT);
        }
        if (str_contains($value, '-')) {
            if (substr_count($value, '-') > 1) {
                return false;
            }
            $chunks = explode('-', $value);
            $chunks[0] = $this->convert_literals($chunks[0]);
            $chunks[1] = $this->convert_literals($chunks[1]);
            if ('*' === $chunks[0] || '*' === $chunks[1]) {
                return false;
            }
            return $this->validate($chunks[0]) && $this->validate($chunks[1]);
        }
        if (!is_numeric($value)) {
            return false;
        }
        if (str_contains($value, '.')) {
            return false;
        }
        // We should have a numeric by now, so coerce this into an integer
        $value = (int) $value;
        return \in_array($value, $this->full_range, true);
    }
    protected function timezone_safe_modify(DateTimeInterface $dt, string $modification): DateTimeInterface
    {
        $timezone = $dt->get_timezone();
        $dt = $dt->set_timezone(new \DateTimeZone('UTC'));
        $dt = $dt->modify($modification);
        return $dt->set_timezone($timezone);
    }
    protected function set_time_hour(DateTimeInterface $date, bool $invert, int $original_timestamp): DateTimeInterface
    {
        $date = $date->set_time((int) $date->format('H'), $invert ? 59 : 0);
        // setTime caused the offset to change, moving time in the wrong direction
        $actual_timestamp = $date->format('U');
        if (!$invert && $actual_timestamp <= $original_timestamp) {
            $date = $this->timezone_safe_modify($date, '+1 hour');
        } elseif ($invert && $actual_timestamp >= $original_timestamp) {
            $date = $this->timezone_safe_modify($date, '-1 hour');
        }
        return $date;
    }
}