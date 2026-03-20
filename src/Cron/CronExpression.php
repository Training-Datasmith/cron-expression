<?php

declare (strict_types=1);
namespace Cron;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
/**
 * CRON expression parser that can determine whether or not a CRON expression is
 * due to run, the next run date and previous run date of a CRON expression.
 * The determinations made by this class are accurate if checked run once per
 * minute (seconds are dropped from date time comparisons).
 *
 * Schedule parts must map to:
 * minute [0-59], hour [0-23], day of month, month [1-12|JAN-DEC], day of week
 * [1-7|MON-SUN], and an optional year.
 *
 * @see http://en.wikipedia.org/wiki/Cron
 */
class Cron_Expression implements \Stringable
{
    public const MINUTE = 0;
    public const HOUR = 1;
    public const DAY = 2;
    public const MONTH = 3;
    public const WEEKDAY = 4;
    /** @deprecated */
    public const YEAR = 5;
    public const MAPPINGS = ['@yearly' => '0 0 1 1 *', '@annually' => '0 0 1 1 *', '@monthly' => '0 0 1 * *', '@weekly' => '0 0 * * 0', '@daily' => '0 0 * * *', '@midnight' => '0 0 * * *', '@hourly' => '0 * * * *'];
    /**
     * @var array<int, string> CRON expression parts
     */
    protected $cron_parts;
    /**
     * @var FieldFactoryInterface CRON field factory
     */
    protected \Cron\Field_Factory_Interface $field_factory;
    /**
     * @var int Max iteration count when searching for next run date
     */
    protected $max_iteration_count = 1000;
    /**
     * @var array<int, int> Order in which to test of cron parts
     */
    protected static $order = [self::YEAR, self::MONTH, self::DAY, self::WEEKDAY, self::HOUR, self::MINUTE];
    /**
     * @var array<string, string>
     */
    private static array $registered_aliases = self::MAPPINGS;
    /**
     * Registered a user defined CRON Expression Alias.
     *
     * @throws LogicException If the expression or the alias name are invalid
     *                         or if the alias is already registered.
     */
    public static function register_alias(string $alias, string $expression): void
    {
        try {
            new self($expression);
        } catch (InvalidArgumentException $exception) {
            throw new LogicException("The expression `{$expression}` is invalid", 0, $exception);
        }
        $shortcut = strtolower($alias);
        if (1 !== preg_match('/^@\w+$/', $shortcut)) {
            throw new LogicException("The alias `{$alias}` is invalid. It must start with an `@` character and contain alphanumeric (letters, numbers, regardless of case) plus underscore (_).");
        }
        if (isset(self::$registered_aliases[$shortcut])) {
            throw new LogicException("The alias `{$alias}` is already registered.");
        }
        self::$registered_aliases[$shortcut] = $expression;
    }
    /**
     * Unregistered a user defined CRON Expression Alias.
     *
     * @throws LogicException If the user tries to unregister a built-in alias
     */
    public static function unregister_alias(string $alias): bool
    {
        $shortcut = strtolower($alias);
        if (isset(self::MAPPINGS[$shortcut])) {
            throw new LogicException("The alias `{$alias}` is a built-in alias; it can not be unregistered.");
        }
        if (!isset(self::$registered_aliases[$shortcut])) {
            return false;
        }
        unset(self::$registered_aliases[$shortcut]);
        return true;
    }
    /**
     * Tells whether a CRON Expression alias is registered.
     */
    public static function supports_alias(string $alias): bool
    {
        return isset(self::$registered_aliases[strtolower($alias)]);
    }
    /**
     * Returns all registered aliases as an associated array where the aliases are the key
     * and their associated expressions are the values.
     *
     * @return array<string, string>
     */
    public static function get_aliases(): array
    {
        return self::$registered_aliases;
    }
    /**
     * @deprecated since version 3.0.2, use __construct instead.
     */
    public static function factory(string $expression, ?Field_Factory_Interface $field_factory = null): Cron_Expression
    {
        /** @phpstan-ignore-next-line */
        return new static($expression, $field_factory);
    }
    /**
     * Validate a CronExpression.
     *
     * @param string $expression the CRON expression to validate
     *
     * @return bool True if a valid CRON expression was passed. False if not.
     */
    public static function is_valid_expression(string $expression): bool
    {
        try {
            new Cron_Expression($expression);
        } catch (InvalidArgumentException) {
            return false;
        }
        return true;
    }
    /**
     * Parse a CRON expression.
     *
     * @param string $expression CRON expression (e.g. '8 * * * *')
     * @param null|FieldFactoryInterface $fieldFactory Factory to create cron fields
     * @throws InvalidArgumentException
     */
    public function __construct(string $expression, ?Field_Factory_Interface $field_factory = null)
    {
        $shortcut = strtolower($expression);
        $expression = self::$registered_aliases[$shortcut] ?? $expression;
        $this->field_factory = $field_factory ?: new Field_Factory();
        $this->set_expression($expression);
    }
    /**
     * Set or change the CRON expression.
     *
     * @param string $value CRON expression (e.g. 8 * * * *)
     *
     * @throws \InvalidArgumentException if not a valid CRON expression
     */
    public function set_expression(string $value): Cron_Expression
    {
        $split = preg_split('/\s/', $value, -1, PREG_SPLIT_NO_EMPTY);
        if (!\is_array($split)) {
            throw new InvalidArgumentException($value . ' is not a valid CRON expression');
        }
        $not_enough_parts = \count($split) < 5;
        $question_mark_in_invalid_part = array_key_exists(0, $split) && $split[0] === '?' || array_key_exists(1, $split) && $split[1] === '?' || array_key_exists(3, $split) && $split[3] === '?';
        $too_many_question_marks = array_key_exists(2, $split) && $split[2] === '?' && array_key_exists(4, $split) && $split[4] === '?';
        if ($not_enough_parts || $question_mark_in_invalid_part || $too_many_question_marks) {
            throw new InvalidArgumentException($value . ' is not a valid CRON expression');
        }
        $this->cron_parts = $split;
        foreach ($this->cron_parts as $position => $part) {
            $this->set_part($position, $part);
        }
        return $this;
    }
    /**
     * Set part of the CRON expression.
     *
     * @param int $position The position of the CRON expression to set
     * @param string $value The value to set
     *
     * @throws \InvalidArgumentException if the value is not valid for the part
     */
    public function set_part(int $position, string $value): Cron_Expression
    {
        if (!$this->field_factory->get_field($position)->validate($value)) {
            throw new InvalidArgumentException('Invalid CRON field value ' . $value . ' at position ' . $position);
        }
        $this->cron_parts[$position] = $value;
        return $this;
    }
    /**
     * Set max iteration count for searching next run dates.
     *
     * @param int $maxIterationCount Max iteration count when searching for next run date
     */
    public function set_max_iteration_count(int $max_iteration_count): Cron_Expression
    {
        $this->max_iteration_count = $max_iteration_count;
        return $this;
    }
    /**
     * Get a next run date relative to the current date or a specific date
     *
     * @param string|\DateTimeInterface $currentTime      Relative calculation date
     * @param int                       $nth              Number of matches to skip before returning a
     *                                                    matching next run date.  0, the default, will return the
     *                                                    current date and time if the next run date falls on the
     *                                                    current date and time.  Setting this value to 1 will
     *                                                    skip the first match and go to the second match.
     *                                                    Setting this value to 2 will skip the first 2
     *                                                    matches and so on.
     * @param bool                      $allowCurrentDate Set to TRUE to return the current date if
     *                                                    it matches the cron expression.
     * @param null|string               $timeZone         TimeZone to use instead of the system default
     *
     * @throws \RuntimeException on too many iterations
     * @throws \Exception
     */
    public function get_next_run_date($current_time = 'now', int $nth = 0, bool $allow_current_date = false, $time_zone = null): DateTime
    {
        return $this->get_run_date($current_time, $nth, false, $allow_current_date, $time_zone);
    }
    /**
     * Get a previous run date relative to the current date or a specific date.
     *
     * @param string|\DateTimeInterface $currentTime      Relative calculation date
     * @param int                       $nth              Number of matches to skip before returning
     * @param bool                      $allowCurrentDate Set to TRUE to return the
     *                                                    current date if it matches the cron expression
     * @param null|string               $timeZone         TimeZone to use instead of the system default
     *
     * @throws \RuntimeException on too many iterations
     * @throws \Exception
     *
     *
     * @see \Cron\CronExpression::getNextRunDate
     */
    public function get_previous_run_date($current_time = 'now', int $nth = 0, bool $allow_current_date = false, $time_zone = null): DateTime
    {
        return $this->get_run_date($current_time, $nth, true, $allow_current_date, $time_zone);
    }
    /**
     * Get multiple run dates starting at the current date or a specific date.
     *
     * @param int $total Set the total number of dates to calculate
     * @param string|\DateTimeInterface|null $currentTime Relative calculation date
     * @param bool $invert Set to TRUE to retrieve previous dates
     * @param bool $allowCurrentDate Set to TRUE to return the
     *                               current date if it matches the cron expression
     * @param null|string $timeZone TimeZone to use instead of the system default
     *
     * @return \DateTime[] Returns an array of run dates
     */
    public function get_multiple_run_dates(int $total, $current_time = 'now', bool $invert = false, bool $allow_current_date = false, $time_zone = null): array
    {
        $time_zone = $this->determine_time_zone($current_time, $time_zone);
        if ('now' === $current_time) {
            $current_time = new DateTime();
        } elseif ($current_time instanceof DateTime) {
            $current_time = clone $current_time;
        } elseif ($current_time instanceof DateTimeImmutable) {
            $current_time = DateTime::create_from_format('U', $current_time->format('U'));
        } elseif (\is_string($current_time)) {
            $current_time = new DateTime($current_time);
        }
        if (!$current_time instanceof DateTime) {
            throw new InvalidArgumentException('invalid current time');
        }
        $current_time->set_timezone(new DateTimeZone($time_zone));
        $matches = [];
        for ($i = 0; $i < $total; ++$i) {
            try {
                $result = $this->get_run_date($current_time, 0, $invert, $allow_current_date, $time_zone);
            } catch (RuntimeException) {
                break;
            }
            $allow_current_date = false;
            $current_time = clone $result;
            $matches[] = $result;
        }
        return $matches;
    }
    /**
     * Get all or part of the CRON expression.
     *
     * @param int|string|null $part specify the part to retrieve or NULL to get the full
     *                     cron schedule string
     *
     * @return null|string Returns the CRON expression, a part of the
     *                     CRON expression, or NULL if the part was specified but not found
     */
    public function get_expression($part = null): ?string
    {
        if (null === $part) {
            return implode(' ', $this->cron_parts);
        }
        if (array_key_exists($part, $this->cron_parts)) {
            return $this->cron_parts[$part];
        }
        return null;
    }
    /**
     * Gets the parts of the cron expression as an array.
     *
     * @return string[]
     *   The array of parts that make up this expression.
     */
    public function get_parts()
    {
        return $this->cron_parts;
    }
    /**
     * Helper method to output the full expression.
     *
     * @return string Full CRON expression
     */
    public function __toString(): string
    {
        return (string) $this->get_expression();
    }
    /**
     * Determine if the cron is due to run based on the current date or a
     * specific date.  This method assumes that the current number of
     * seconds are irrelevant, and should be called once per minute.
     *
     * @param string|\DateTimeInterface $currentTime Relative calculation date
     * @param null|string               $timeZone    TimeZone to use instead of the system default
     *
     * @return bool Returns TRUE if the cron is due to run or FALSE if not
     */
    public function is_due($current_time = 'now', $time_zone = null): bool
    {
        $time_zone = $this->determine_time_zone($current_time, $time_zone);
        if ('now' === $current_time) {
            $current_time = new DateTime();
        } elseif ($current_time instanceof DateTime) {
            $current_time = clone $current_time;
        } elseif ($current_time instanceof DateTimeImmutable) {
            $current_time = DateTime::create_from_format('U', $current_time->format('U'));
        } elseif (\is_string($current_time)) {
            $current_time = new DateTime($current_time);
        }
        if (!$current_time instanceof DateTime) {
            throw new InvalidArgumentException('invalid current time');
        }
        $current_time->set_timezone(new DateTimeZone($time_zone));
        // drop the seconds to 0
        $current_time->set_time((int) $current_time->format('H'), (int) $current_time->format('i'), 0);
        try {
            return $this->get_next_run_date($current_time, 0, true)->get_timestamp() === $current_time->get_timestamp();
        } catch (Exception) {
            return false;
        }
    }
    /**
     * Get the next or previous run date of the expression relative to a date.
     *
     * @param string|\DateTimeInterface|null $currentTime Relative calculation date
     * @param int $nth Number of matches to skip before returning
     * @param bool $invert Set to TRUE to go backwards in time
     * @param bool $allowCurrentDate Set to TRUE to return the
     *                               current date if it matches the cron expression
     * @param string|null $timeZone  TimeZone to use instead of the system default
     *
     * @throws \RuntimeException on too many iterations
     * @throws Exception
     */
    protected function get_run_date($current_time = null, int $nth = 0, bool $invert = false, bool $allow_current_date = false, $time_zone = null): DateTime
    {
        $time_zone = $this->determine_time_zone($current_time, $time_zone);
        if ($current_time instanceof DateTime) {
            $current_date = clone $current_time;
        } elseif ($current_time instanceof DateTimeImmutable) {
            $current_date = DateTime::create_from_format('U', $current_time->format('U'));
        } elseif (\is_string($current_time)) {
            $current_date = new DateTime($current_time);
        } else {
            $current_date = new DateTime('now');
        }
        if (!$current_date instanceof DateTime) {
            throw new InvalidArgumentException('invalid current date');
        }
        $current_date->set_timezone(new DateTimeZone($time_zone));
        // Workaround for setTime causing an offset change: https://bugs.php.net/bug.php?id=81074
        $current_date = DateTime::create_from_format('!Y-m-d H:iO', $current_date->format('Y-m-d H:iP'), $current_date->get_timezone());
        if ($current_date === false) {
            throw new \RuntimeException('Unable to create date from format');
        }
        $current_date->set_timezone(new DateTimeZone($time_zone));
        $next_run = clone $current_date;
        // We don't have to satisfy * or null fields
        $parts = [];
        $fields = [];
        foreach (self::$order as $position) {
            $part = $this->get_expression($position);
            if (null === $part) {
                continue;
            }
            if ('*' === $part) {
                continue;
            }
            $parts[$position] = $part;
            $fields[$position] = $this->field_factory->get_field($position);
        }
        if (isset($parts[self::DAY]) && isset($parts[self::WEEKDAY])) {
            $dom_expression = sprintf('%s %s %s %s *', $this->get_expression(0), $this->get_expression(1), $this->get_expression(2), $this->get_expression(3));
            $dow_expression = sprintf('%s %s * %s %s', $this->get_expression(0), $this->get_expression(1), $this->get_expression(3), $this->get_expression(4));
            $dom_expression = new self($dom_expression);
            $dow_expression = new self($dow_expression);
            $dom_run_dates = $dom_expression->get_multiple_run_dates($nth + 1, $current_time, $invert, $allow_current_date, $time_zone);
            $dow_run_dates = $dow_expression->get_multiple_run_dates($nth + 1, $current_time, $invert, $allow_current_date, $time_zone);
            if ($parts[self::DAY] === '?' || $parts[self::DAY] === '*') {
                $dom_run_dates = [];
            }
            if ($parts[self::WEEKDAY] === '?' || $parts[self::WEEKDAY] === '*') {
                $dow_run_dates = [];
            }
            $combined = array_merge($dom_run_dates, $dow_run_dates);
            usort($combined, fn($a, $b) => $a->format('Y-m-d H:i:s') <=> $b->format('Y-m-d H:i:s'));
            if ($invert) {
                $combined = array_reverse($combined);
            }
            return $combined[$nth];
        }
        // Set a hard limit to bail on an impossible date
        for ($i = 0; $i < $this->max_iteration_count; ++$i) {
            foreach ($parts as $position => $part) {
                $satisfied = false;
                // Get the field object used to validate this part
                $field = $fields[$position];
                // Check if this is singular or a list
                if (!str_contains($part, ',')) {
                    $satisfied = $field->is_satisfied_by($next_run, $part, $invert);
                } else {
                    foreach (array_map(trim(...), explode(',', $part)) as $list_part) {
                        if ($field->is_satisfied_by($next_run, $list_part, $invert)) {
                            $satisfied = true;
                            break;
                        }
                    }
                }
                // If the field is not satisfied, then start over
                if (!$satisfied) {
                    $field->increment($next_run, $invert, $part);
                    continue 2;
                }
            }
            // Skip this match if needed
            if (!$allow_current_date && $next_run == $current_date || --$nth > -1) {
                $this->field_factory->get_field(self::MINUTE)->increment($next_run, $invert, $parts[self::MINUTE] ?? null);
                continue;
            }
            return $next_run;
        }
        // @codeCoverageIgnoreStart
        throw new RuntimeException('Impossible CRON expression');
        // @codeCoverageIgnoreEnd
    }
    /**
     * Workout what timeZone should be used.
     *
     * @param string|\DateTimeInterface|null $currentTime Relative calculation date
     * @param string|null $timeZone TimeZone to use instead of the system default
     */
    protected function determine_time_zone($current_time, ?string $time_zone): string
    {
        if (null !== $time_zone) {
            return $time_zone;
        }
        if ($current_time instanceof DateTimeInterface) {
            return $current_time->get_timezone()->get_name();
        }
        return date_default_timezone_get();
    }
}