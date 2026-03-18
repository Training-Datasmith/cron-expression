<?php

declare(strict_types=1);

namespace Cron;

use InvalidArgumentException;

/**
 * CRON field factory implementing a flyweight factory.
 *
 * @see http://en.wikipedia.org/wiki/Cron
 */
class FieldFactory implements FieldFactoryInterface
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
    public function getField(int $position): FieldInterface
    {
        return $this->fields[$position] ?? $this->fields[$position] = $this->instantiateField($position);
    }

    private function instantiateField(int $position): FieldInterface
    {
        return match ($position) {
            CronExpression::MINUTE => new MinutesField(),
            CronExpression::HOUR => new HoursField(),
            CronExpression::DAY => new DayOfMonthField(),
            CronExpression::MONTH => new MonthField(),
            CronExpression::WEEKDAY => new DayOfWeekField(),
            default => throw new InvalidArgumentException(
                ($position + 1) . ' is not a valid position'
            ),
        };
    }
}
