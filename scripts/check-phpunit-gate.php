<?php

declare(strict_types=1);

if ($argc < 2) {
    fwrite(STDERR, "Usage: php scripts/check-phpunit-gate.php <phpunit-output-log>\n");
    exit(2);
}

$logPath = $argv[1];
if (!is_readable($logPath)) {
    fwrite(STDERR, "Cannot read {$logPath}\n");
    exit(2);
}

$baselinePath = dirname(__DIR__) . '/scripts/phpunit-baseline.txt';
if (!is_readable($baselinePath)) {
    fwrite(STDERR, "Missing baseline file: {$baselinePath}\n");
    exit(2);
}

$okLine = null;
$summaryLine = null;
foreach (file($baselinePath, FILE_IGNORE_NEW_LINES) as $line) {
    if (str_starts_with($line, 'PHPUNIT_OK_LINE=')) {
        $okLine = substr($line, strlen('PHPUNIT_OK_LINE='));
    }
    if (str_starts_with($line, 'PHPUNIT_SUMMARY_LINE=')) {
        $summaryLine = substr($line, strlen('PHPUNIT_SUMMARY_LINE='));
    }
}

if ($okLine === null || $summaryLine === null) {
    fwrite(STDERR, "Baseline file is missing PHPUNIT_OK_LINE or PHPUNIT_SUMMARY_LINE.\n");
    exit(2);
}

$text = file_get_contents($logPath);
if ($text === false) {
    fwrite(STDERR, "Failed to read {$logPath}\n");
    exit(2);
}

$stripped = preg_replace('/\x1b\[[0-9;]*[a-zA-Z]/', '', $text) ?? $text;

foreach (preg_split('/\R/', $stripped) as $line) {
    if ($line === '') {
        continue;
    }
    if (preg_match('/^(Warning|Deprecated|Notice):/', $line)
        || preg_match('/^PHP (Warning|Deprecated|Notice):/', $line)) {
        fwrite(STDERR, "Disallowed PHPUnit output line: {$line}\n");
        exit(1);
    }
}

if (!preg_match('/' . preg_quote($okLine, '/') . '/', $stripped)) {
    fwrite(STDERR, "PHPUnit output missing baseline OK line: {$okLine}\n");
    exit(1);
}

if (!preg_match('/^' . preg_quote($summaryLine, '/') . '$/m', $stripped)) {
    fwrite(STDERR, "PHPUnit output missing baseline summary line: {$summaryLine}\n");
    exit(1);
}

fwrite(STDOUT, "PHPUnit gate passed ({$summaryLine}).\n");
exit(0);
