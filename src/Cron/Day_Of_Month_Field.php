<?php

declare (strict_types=1);
namespace Cron;

use DateTime;
use DateTimeInterface;
/**
 * Day of month field.  Allows: * , / - ? L W.
 *
 * 'L' stands for "last" and specifies the last day of the month.
 *
 * The 'W' character is used to specify the weekday (Monday-Friday) nearest the
 * given day. As an example, if you were to specify "15W" as the value for the
 * day-of-month field, the meaning is: "the nearest weekday to the 15th of the
 * month". So if the 15th is a Saturday, the trigger will fire on Friday the
 * 14th. If the 15th is a Sunday, the trigger will fire on Monday the 16th. If
 * the 15th is a Tuesday, then it will fire on Tuesday the 15th. However if you
 * specify "1W" as the value for day-of-month, and the 1st is a Saturday, the
 * trigger will fire on Monday the 3rd, as it will not 'jump' over the boundary
 * of a month's days. The 'W' character can only be specified when the
 * day-of-month is a single day, not a range or list of days.
 *
 * @author Michael Dowling <mtdowling@gmail.com>
 */
class Day_Of_Month_Field extends Abstract_Field
{
    /**
     * {@inheritdoc}
     */
    protected $range_start = 1;
    /**
     * {@inheritdoc}
     */
    protected $range_end = 31;
    /**
     * Get the nearest day of the week for a given day in a month.
     *
     * @param int $currentYear Current year
     * @param int $currentMonth Current month
     * @param int $targetDay Target day of the month
     *
     * @return \DateTime|null Returns the nearest date
     */
    private static function get_nearest_weekday(int $current_year, int $current_month, int $target_day): ?DateTime
    {
        $tday = str_pad((string) $target_day, 2, '0', STR_PAD_LEFT);
        $target = DateTime::create_from_format('Y-m-d', "{$current_year}-{$current_month}-{$tday}");
        if ($target === false) {
            return null;
        }
        $current_weekday = (int) $target->format('N');
        if ($current_weekday < 6) {
            return $target;
        }
        $last_day_of_month = $target->format('t');
        foreach ([-1, 1, -2, 2] as $i) {
            $adjusted = $target_day + $i;
            if ($adjusted > 0 && $adjusted <= $last_day_of_month) {
                $target->set_date($current_year, $current_month, $adjusted);
                if ((int) $target->format('N') < 6 && (int) $target->format('m') === $current_month) {
                    return $target;
                }
            }
        }
        return null;
    }
    /**
     * {@inheritdoc}
     */
    public function is_satisfied_by(DateTimeInterface $date, $value, bool $invert): bool
    {
        // ? states that the field value is to be skipped
        if ('?' === $value) {
            return true;
        }
        $field_value = $date->format('d');
        // Check to see if this is the last day of the month
        if ('L' === $value) {
            return $field_value === $date->format('t');
        }
        // Check to see if this is the nearest weekday to a particular value
        if ($w_position = strpos($value, 'W')) {
            // Parse the target day
            $target_day = (int) substr($value, 0, $w_position);
            // Find out if the current day is the nearest day of the week
            $nearest = self::get_nearest_weekday((int) $date->format('Y'), (int) $date->format('m'), $target_day);
            if ($nearest) {
                return $date->format('j') === $nearest->format('j');
            }
            throw new \RuntimeException('Unable to return nearest weekday');
        }
        return $this->is_satisfied((int) $date->format('d'), $value);
    }
    /**
     * @inheritDoc
     *
     * @param \DateTime|\DateTimeImmutable $date
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
        // Validate that a list don't have W or L
        if (str_contains($value, ',') && (str_contains($value, 'W') || str_contains($value, 'L'))) {
            return false;
        }
        if (!$basic_checks) {
            if ('?' === $value) {
                return true;
            }
            if ('L' === $value) {
                return true;
            }
            if (preg_match('/^(.*)W$/', $value, $matches)) {
                return $this->validate($matches[1]);
            }
            return false;
        }
        return $basic_checks;
    }
}