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
$allowlistPath = dirname(__DIR__) . '/scripts/allowed-skipped-tests.txt';
if (!is_readable($baselinePath)) {
    fwrite(STDERR, "Missing baseline file: {$baselinePath}\n");
    exit(2);
}
if (!is_readable($allowlistPath)) {
    fwrite(STDERR, "Missing allowlist file: {$allowlistPath}\n");
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

/** @var array<string, string> $allowedSkips */
$allowedSkips = [];
foreach (file($allowlistPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#')) {
        continue;
    }
    $parts = explode('|', $line, 2);
    if (count($parts) !== 2) {
        fwrite(STDERR, "Invalid allowlist line: {$line}\n");
        exit(2);
    }
    $allowedSkips[trim($parts[0])] = trim($parts[1]);
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
    if (preg_match('/^(PHP )?(Warning|Deprecated|Notice):/', $line)) {
        fwrite(STDERR, "Disallowed PHPUnit output line: {$line}\n");
        exit(1);
    }
}

if (!preg_match('/^' . preg_quote($okLine, '/') . '$/m', $stripped)) {
    fwrite(STDERR, "PHPUnit output missing anchored baseline OK line: {$okLine}\n");
    exit(1);
}

if (!preg_match('/^' . preg_quote($summaryLine, '/') . '$/m', $stripped)) {
    fwrite(STDERR, "PHPUnit output missing anchored baseline summary line: {$summaryLine}\n");
    exit(1);
}

if (!preg_match('/^Tests: 175, Assertions: 798, Skipped: 4\.$/m', $stripped)) {
    fwrite(STDERR, "PHPUnit summary counts must be exactly Tests: 175, Assertions: 798, Skipped: 4.\n");
    exit(1);
}

/** @var list<array{id: string, reason: string}> $skipped */
$skipped = [];
if (preg_match('/There were (\d+) skipped tests:/', $stripped, $countMatch)) {
    $count = (int) $countMatch[1];
    if ($count !== count($allowedSkips)) {
        fwrite(STDERR, "Unexpected skipped test count: {$count} (expected " . count($allowedSkips) . ").\n");
        exit(1);
    }

    if (preg_match('/There were \d+ skipped tests:\R(.*)\R(?:OK, but|\Z)/s', $stripped, $sectionMatch)) {
        $section = trim($sectionMatch[1]);
        foreach (preg_split('/\R\R/', $section) as $block) {
            $lines = preg_split('/\R/', trim($block));
            if ($lines === false || count($lines) < 2) {
                continue;
            }
            if (!preg_match('/^\d+\) (.+)$/', $lines[0], $idMatch)) {
                continue;
            }
            $skipped[] = ['id' => trim($idMatch[1]), 'reason' => trim($lines[1])];
        }
    }
}

if (count($skipped) !== count($allowedSkips)) {
    fwrite(STDERR, 'Could not parse all skipped tests from PHPUnit output.' . "\n");
    exit(1);
}

foreach ($skipped as $skip) {
    $id = $skip['id'];
    $reason = $skip['reason'];
    if (!isset($allowedSkips[$id])) {
        fwrite(STDERR, "Unexpected skipped test: {$id}\n");
        exit(1);
    }
    if ($allowedSkips[$id] !== $reason) {
        fwrite(STDERR, "Skip reason mismatch for {$id}: expected \"{$allowedSkips[$id]}\", got \"{$reason}\"\n");
        exit(1);
    }
}

$foundIds = array_column($skipped, 'id');
foreach (array_keys($allowedSkips) as $requiredId) {
    if (!in_array($requiredId, $foundIds, true)) {
        fwrite(STDERR, "Allowlisted skip not present in run: {$requiredId}\n");
        exit(1);
    }
}

fwrite(STDOUT, "PHPUnit gate passed ({$summaryLine}).\n");
exit(0);
