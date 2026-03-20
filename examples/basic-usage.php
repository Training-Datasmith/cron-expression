<?php

declare(strict_types=1);

/**
 * Example: Parsing and evaluating CRON expressions.
 */

use Cron\Cron_Expression;

require_once __DIR__ . '/../vendor/autoload.php';

// --- 1. Validate an expression ---
var_dump(Cron_Expression::is_valid_expression('0 0 * * *'));  // bool(true)
var_dump(Cron_Expression::is_valid_expression('99 * * * *')); // bool(false)

// --- 2. Check if a schedule is due right now ---
$hourly = new Cron_Expression('@hourly');
echo 'Due now: ' . ($hourly->is_due() ? 'yes' : 'no') . PHP_EOL;

// --- 3. Next and previous run dates ---
$expr = new Cron_Expression('30 8 * * 1-5'); // 08:30 on weekdays

$next = $expr->get_next_run_date();
echo 'Next run: ' . $next->format('Y-m-d H:i') . PHP_EOL;

$prev = $expr->get_previous_run_date();
echo 'Previous run: ' . $prev->format('Y-m-d H:i') . PHP_EOL;

// --- 4. Multiple upcoming dates ---
$upcoming = $expr->get_multiple_run_dates(3);
echo 'Next 3 runs:' . PHP_EOL;
foreach ($upcoming as $date) {
    echo '  ' . $date->format('Y-m-d H:i') . PHP_EOL;
}

// --- 5. Custom alias ---
Cron_Expression::register_alias('@business-open', '0 9 * * 1-5');
$custom = new Cron_Expression('@business-open');
echo 'Business open expression: ' . $custom->get_expression() . PHP_EOL;
// 0 9 * * 1-5
