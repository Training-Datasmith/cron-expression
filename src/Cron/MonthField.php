<?php

declare (strict_types=1);
namespace Cron;

use DateTimeInterface;
/**
 * Month field.  Allows: * , / -.
 */
class Month_Field extends Abstract_Field
{
    /**
     * {@inheritdoc}
     */
    protected $range_start = 1;
    /**
     * {@inheritdoc}
     */
    protected $range_end = 12;
    /**
     * {@inheritdoc}
     */
    protected $literals = [1 => 'JAN', 2 => 'FEB', 3 => 'MAR', 4 => 'APR', 5 => 'MAY', 6 => 'JUN', 7 => 'JUL', 8 => 'AUG', 9 => 'SEP', 10 => 'OCT', 11 => 'NOV', 12 => 'DEC'];
    /**
     * {@inheritdoc}
     */
    public function is_satisfied_by(DateTimeInterface $date, $value, bool $invert): bool
    {
        if ($value === '?') {
            return true;
        }
        $value = $this->convert_literals($value);
        return $this->is_satisfied((int) $date->format('m'), $value);
    }
    /**
     * @inheritDoc
     *
     * @param \DateTime|\DateTimeImmutable $date
     */
    public function increment(DateTimeInterface &$date, $invert = false, $parts = null): Field_Interface
    {
        if (!$invert) {
            $date = $date->modify('first day of next month');
            $date = $date->set_time(0, 0);
        } else {
            $date = $date->modify('last day of previous month');
            $date = $date->set_time(23, 59);
        }
        return $this;
    }
}