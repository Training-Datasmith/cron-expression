# Architecture: cron-expression

## Purpose

A PHP library for parsing, validating, and evaluating CRON expressions. It can determine whether a schedule is currently due, find the next/previous run date, and enumerate multiple future run dates. It supports standard five-field CRON notation plus named aliases like `@daily`.

## Directory Structure

```
src/
  Cron/
    Cron_Expression.php          — Public API: parse, validate, next/previous run date
    Abstract_Field.php           — Shared field logic (ranges, steps, wildcards)
    Day_Of_Month_Field.php       — Field: position 2 (1-31, L, W, ?)
    Day_Of_Week_Field.php        — Field: position 4 (0-7 or MON-SUN, L, #, ?)
    Hours_Field.php              — Field: position 1 (0-23)
    Minutes_Field.php            — Field: position 0 (0-59)
    Month_Field.php              — Field: position 3 (1-12 or JAN-DEC)
    Field_Factory.php            — Maps position integer to the correct Field object
    Field_Factory_Interface.php  — Pluggable factory contract
    Field_Interface.php          — Per-field contract: is_satisfied_by, increment, validate

tests/
  Cron/                          — PHPUnit tests for every field and the main expression class
```

## Key Design Decisions

- **Field-per-position strategy** — each of the five CRON positions is handled by a dedicated field class, making it easy to extend (e.g., adding seconds).
- **Iteration-based next-date algorithm** — `get_run_date()` advances a DateTime one minute at a time, testing all fields until satisfied; a 1000-iteration hard limit prevents infinite loops on pathological expressions.
- **DOM + DOW union semantics** — when both day-of-month and day-of-week are specified, the implementation follows the CRON standard: either condition matching is sufficient (OR, not AND).
- **Pluggable aliases** — `register_alias()` allows registering custom `@shortcut` expressions at runtime; built-ins (`@daily`, `@monthly`, etc.) cannot be overridden.

## Extension Points

- Implement `Field_Factory_Interface` and inject it into `Cron_Expression` to support non-standard fields (e.g., seconds, years).
- Subclass `Abstract_Field` to customise parsing of any individual field.
- Call `Cron_Expression::register_alias()` to add project-specific shortcut expressions.

## Dependency Flow

```
Cron_Expression
  └── Field_Factory (get_field(position))
        └── Abstract_Field subclasses
              └── is_satisfied_by(DateTime, value) / increment(DateTime)
```
