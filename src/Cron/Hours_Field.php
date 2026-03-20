<?php

declare (strict_types=1);
namespace Cron;

use DateTimeInterface;
use DateTimeZone;
/**
 * Hours field.  Allows: * , / -.
 */
class Hours_Field extends Abstract_Field
{
    /**
     * {@inheritdoc}
     */
    protected $range_start = 0;
    /**
     * {@inheritdoc}
     */
    protected $range_end = 23;
    /**
     * @var list<array<string, bool|int|string>>|null Transitions returned by DateTimeZone::getTransitions()
     */
    protected $transitions = [];
    /**
     * @var int|null Timestamp of the start of the transitions range
     */
    protected $transitions_start;
    /**
     * @var int|null Timestamp of the end of the transitions range
     */
    protected $transitions_end;
    /**
     * {@inheritdoc}
     */
    public function is_satisfied_by(DateTimeInterface $date, $value, bool $invert): bool
    {
        $check_value = (int) $date->format('H');
        $retval = $this->is_satisfied($check_value, $value);
        if ($retval) {
            return $retval;
        }
        // Are we on the edge of a transition
        $last_transition = $this->get_past_transition($date);
        if ($last_transition !== null && $last_transition['ts'] > (int) $date->format('U') - 3600) {
            $dt_last_offset = clone $date;
            $this->timezone_safe_modify($dt_last_offset, '-1 hour');
            $last_offset = $dt_last_offset->get_offset();
            $dt_next_offset = clone $date;
            $this->timezone_safe_modify($dt_next_offset, '+1 hour');
            $next_offset = $dt_next_offset->get_offset();
            $offset_change = $next_offset - $last_offset;
            if ($offset_change >= 3600) {
                $check_value -= 1;
                return $this->is_satisfied($check_value, $value);
            }
            if (!$invert && $offset_change <= -3600) {
                $check_value += 1;
                return $this->is_satisfied($check_value, $value);
            }
        }
        return $retval;
    }
    /**
     * @return non-empty-array<string, bool|int|string>|null
     */
    public function get_past_transition(DateTimeInterface $date): ?array
    {
        $current_timestamp = (int) $date->format('U');
        if ($this->transitions === null || $this->transitions_start < $current_timestamp + 86400 || $this->transitions_end > $current_timestamp - 86400) {
            // We start a day before current time so we can differentiate between the first transition entry
            // and a change that happens now
            $dt_limit_start = clone $date;
            $dt_limit_start = $dt_limit_start->modify('-12 months');
            $dt_limit_end = clone $date;
            $dt_limit_end = $dt_limit_end->modify('+12 months');
            $this->transitions = $date->get_timezone()->get_transitions($dt_limit_start->get_timestamp(), $dt_limit_end->get_timestamp());
            if (empty($this->transitions)) {
                return null;
            }
            $this->transitions_start = $dt_limit_start->get_timestamp();
            $this->transitions_end = $dt_limit_end->get_timestamp();
        }
        $next_transition = null;
        foreach ($this->transitions as $transition) {
            if ($transition['ts'] > $current_timestamp) {
                continue;
            }
            if ($next_transition !== null && $transition['ts'] < $next_transition['ts']) {
                continue;
            }
            $next_transition = $transition;
        }
        return $next_transition ?? null;
    }
    /**
     * {@inheritdoc}
     *
     * @param string|null                  $parts
     */
    public function increment(DateTimeInterface &$date, $invert = false, $parts = null): Field_Interface
    {
        $original_timestamp = (int) $date->format('U');
        // Change timezone to UTC temporarily. This will
        // allow us to go back or forwards and hour even
        // if DST will be changed between the hours.
        if (null === $parts || '*' === $parts) {
            if ($invert) {
                $date = $date->sub(new \DateInterval('PT1H'));
            } else {
                $date = $date->add(new \DateInterval('PT1H'));
            }
            $date = $this->set_time_hour($date, $invert, $original_timestamp);
            return $this;
        }
        $parts = str_contains($parts, ',') ? explode(',', $parts) : [$parts];
        $hours = [];
        foreach ($parts as $part) {
            $hours = array_merge($hours, $this->get_range_for_expression($part, 23));
        }
        $current_hour = (int) $date->format('H');
        $position = $invert ? \count($hours) - 1 : 0;
        $count_hours = \count($hours);
        if ($count_hours > 1) {
            for ($i = 0; $i < $count_hours - 1; ++$i) {
                if (!$invert && $current_hour >= $hours[$i] && $current_hour < $hours[$i + 1] || $invert && $current_hour > $hours[$i] && $current_hour <= $hours[$i + 1]) {
                    $position = $invert ? $i : $i + 1;
                    break;
                }
            }
        }
        $target = (int) $hours[$position];
        $original_hour = (int) $date->format('H');
        $original_dst = (int) $date->format('I');
        $original_day = (int) $date->format('d');
        $previous_offset = $date->get_offset();
        if (!$invert) {
            if ($original_hour >= $target) {
                $distance = 24 - $original_hour;
                $date = $this->timezone_safe_modify($date, "+{$distance} hours");
                $actual_day = (int) $date->format('d');
                $actual_hour = (int) $date->format('H');
                if ($actual_day !== $original_day + 1 && $actual_hour !== 0) {
                    $offset_change = $previous_offset - $date->get_offset();
                    $date = $this->timezone_safe_modify($date, "+{$offset_change} seconds");
                }
                $original_hour = (int) $date->format('H');
            }
            $distance = $target - $original_hour;
            $date = $this->timezone_safe_modify($date, "+{$distance} hours");
        } else {
            if ($original_hour <= $target) {
                $distance = $original_hour + 1;
                $date = $this->timezone_safe_modify($date, '-' . $distance . ' hours');
                $actual_day = (int) $date->format('d');
                $actual_hour = (int) $date->format('H');
                if ($actual_day !== $original_day - 1 && $actual_hour !== 23) {
                    $offset_change = $previous_offset - $date->get_offset();
                    $date = $this->timezone_safe_modify($date, "+{$offset_change} seconds");
                }
                $original_hour = (int) $date->format('H');
            }
            $distance = $original_hour - $target;
            $date = $this->timezone_safe_modify($date, "-{$distance} hours");
        }
        $actual_dst = (int) $date->format('I');
        if ($original_dst < $actual_dst) {
            $date = $this->timezone_safe_modify($date, '-1 hours');
        }
        $date = $this->set_time_hour($date, $invert, $original_timestamp);
        $actual_hour = (int) $date->format('H');
        if ($invert && ($actual_hour === $target - 1 || $actual_hour === 23 && $target === 0)) {
            $date = $this->timezone_safe_modify($date, '+1 hour');
        }
        return $this;
    }
}