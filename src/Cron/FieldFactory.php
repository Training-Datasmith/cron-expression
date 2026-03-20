<?php

declare (strict_types=1);
namespace Cron;

use InvalidArgumentException;
/**
 * CRON field factory implementing a flyweight factory.
 *
 * @see http://en.wikipedia.org/wiki/Cron
 */
class Field_Factory implements Field_Factory_Interface
{
    /**
     * @var array<int, FieldInterface> Cache of instantiated fields
     */
    private array $fields = [];
    /**
     * Get an instance of a field object for a cron expression position.
     *
     * @param int $position CRON expression position value to retrieve
     *
     * @throws InvalidArgumentException if a position is not valid
     */
    public function get_field(int $position): Field_Interface
    {
        return $this->fields[$position] ?? $this->fields[$position] = $this->instantiate_field($position);
    }
    private function instantiate_field(int $position): Field_Interface
    {
        return match ($position) {
            Cron_Expression::MINUTE => new Minutes_Field(),
            Cron_Expression::HOUR => new Hours_Field(),
            Cron_Expression::DAY => new Day_Of_Month_Field(),
            Cron_Expression::MONTH => new Month_Field(),
            Cron_Expression::WEEKDAY => new Day_Of_Week_Field(),
            default => throw new InvalidArgumentException($position + 1 . ' is not a valid position'),
        };
    }
}