<?php

declare(strict_types=1);

/**
 * Fail if pre-rename repo-owned identifiers remain in tracked sources.
 */

$scanPaths = [
    'src',
    'tests',
    'examples',
    'README.md',
    'ARCHITECTURE.md',
    'composer.json',
    'phpstan.neon',
    'phpunit.xml.dist',
];

$forbidden = [
    'AbstractField',
    'CronExpression',
    'DayOfMonthField',
    'DayOfWeekField',
    'FieldFactoryInterface',
    'FieldFactory',
    'FieldInterface',
    'HoursField',
    'MinutesField',
    'MonthField',
    'isSatisfied',
    'isRange',
    'isIncrementsOfRanges',
    'isInRange',
    'isInIncrementsOfRanges',
    'getRangeForExpression',
    'convertLiterals',
    'timezoneSafeModify',
    'setTimeHour',
    'registerAlias',
    'unregisterAlias',
    'supportsAlias',
    'getAliases',
    'isValidExpression',
    'setExpression',
    'setPart',
    'setMaxIterationCount',
    'getNextRunDate',
    'getPreviousRunDate',
    'getMultipleRunDates',
    'getExpression',
    'getParts',
    'isDue',
    'getRunDate',
    'determineTimeZone',
    'getNearestWeekday',
    'isSatisfiedBy',
    'getField',
    'instantiateField',
    'getPastTransition',
    'AbstractFieldTest',
    'CronExpressionTest',
    'DayOfMonthFieldTest',
    'DayOfWeekFieldTest',
    'DaylightSavingsTest',
    'FieldFactoryTest',
    'HoursFieldTest',
    'MinutesFieldTest',
    'MonthFieldTest',
    'fullRange',
    'rangeStart',
    'rangeEnd',
    'cronParts',
    'fieldFactory',
    'maxIterationCount',
    'registeredAliases',
    'nthRange',
    'transitionsStart',
    'transitionsEnd',
];

/**
 * @param list<string> $forbiddenNames
 */
function scan_forbidden(array $forbiddenNames, string $rootDir, array $scanPaths): array
{
    $hits = [];
    foreach ($forbiddenNames as $name) {
        $pattern = '/\b' . preg_quote($name, '/') . '\b/';
        foreach ($scanPaths as $rel) {
            $path = $rootDir . '/' . $rel;
            if (is_file($path)) {
                $contents = file_get_contents($path);
                if ($contents !== false && preg_match($pattern, $contents)) {
                    $hits[] = $rel . ':' . $name;
                }
                continue;
            }
            if (!is_dir($path)) {
                continue;
            }
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
            foreach ($iterator as $fileInfo) {
                if (!$fileInfo instanceof SplFileInfo || !$fileInfo->isFile()) {
                    continue;
                }
                if ($fileInfo->getExtension() !== 'php' && !str_ends_with($fileInfo->getFilename(), '.md')) {
                    continue;
                }
                $contents = file_get_contents($fileInfo->getPathname());
                if ($contents !== false && preg_match($pattern, $contents)) {
                    $hits[] = str_replace($rootDir . '/', '', $fileInfo->getPathname()) . ':' . $name;
                }
            }
        }
    }

    return $hits;
}

$root = dirname(__DIR__);
$argv = $_SERVER['argv'] ?? [];
$selfTest = in_array('--self-test', $argv, true);

if ($selfTest) {
    $probeFile = $root . '/scripts/.rename-residual-self-test-probe.php';
    file_put_contents($probeFile, "<?php\n// probe\nclass CronExpression {}\n");
    $hits = scan_forbidden($forbidden, $root, array_merge($scanPaths, ['scripts/.rename-residual-self-test-probe.php']));
    unlink($probeFile);
    $matched = false;
    foreach ($hits as $hit) {
        if (str_contains($hit, 'CronExpression')) {
            $matched = true;
            break;
        }
    }
    if (!$matched) {
        fwrite(STDERR, "check-rename-residual: self-test did not detect injected old name.\n");
        exit(1);
    }
    fwrite(STDOUT, "check-rename-residual: self-test detected injected old name.\n");
    exit(0);
}

$hits = scan_forbidden($forbidden, $root, $scanPaths);
if ($hits !== []) {
    fwrite(STDERR, "check-rename-residual: old repo-owned names remain:\n");
    foreach ($hits as $hit) {
        fwrite(STDERR, '  ' . $hit . "\n");
    }
    exit(1);
}

fwrite(STDOUT, "check-rename-residual: no old repo-owned names remain.\n");
exit(0);
