<?php

declare (strict_types=1);
namespace Cron;

use DateTimeInterface;
/**
 * Minutes field.  Allows: * , / -.
 */
class Minutes_Field extends Abstract_Field
{
    /**
     * {@inheritdoc}
     */
    protected $range_start = 0;
    /**
     * {@inheritdoc}
     */
    protected $range_end = 59;
    /**
     * {@inheritdoc}
     */
    public function is_satisfied_by(DateTimeInterface $date, $value, bool $invert): bool
    {
        if ($value === '?') {
            return true;
        }
        return $this->is_satisfied((int) $date->format('i'), $value);
    }
    /**
     * {@inheritdoc}
     * {@inheritDoc}
     *
     * @param string|null                  $parts
     */
    public function increment(DateTimeInterface &$date, $invert = false, $parts = null): Field_Interface
    {
        if (is_null($parts)) {
            $date = $this->timezone_safe_modify($date, ($invert ? '-' : '+') . '1 minute');
            return $this;
        }
        $current_minute = (int) $date->format('i');
        $parts = str_contains($parts, ',') ? explode(',', $parts) : [$parts];
        sort($parts);
        $minutes = [];
        foreach ($parts as $part) {
            $minutes = array_merge($minutes, $this->get_range_for_expression($part, 59));
        }
        $position = $invert ? \count($minutes) - 1 : 0;
        if (\count($minutes) > 1) {
            for ($i = 0; $i < \count($minutes) - 1; ++$i) {
                if (!$invert && $current_minute >= $minutes[$i] && $current_minute < $minutes[$i + 1] || $invert && $current_minute > $minutes[$i] && $current_minute <= $minutes[$i + 1]) {
                    $position = $invert ? $i : $i + 1;
                    break;
                }
            }
        }
        $target = (int) $minutes[$position];
        $original_minute = (int) $date->format('i');
        if (!$invert) {
            if ($original_minute >= $target) {
                $distance = 60 - $original_minute;
                $date = $this->timezone_safe_modify($date, "+{$distance} minutes");
                $original_minute = (int) $date->format('i');
            }
            $distance = $target - $original_minute;
            $date = $this->timezone_safe_modify($date, "+{$distance} minutes");
        } else {
            if ($original_minute <= $target) {
                $distance = $original_minute + 1;
                $date = $this->timezone_safe_modify($date, "-{$distance} minutes");
                $original_minute = (int) $date->format('i');
            }
            $distance = $original_minute - $target;
            $date = $this->timezone_safe_modify($date, "-{$distance} minutes");
        }
        return $this;
    }
}