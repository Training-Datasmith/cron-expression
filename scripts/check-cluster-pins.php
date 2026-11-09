<?php

declare(strict_types=1);

/**
 * Verify org-forked packages match composer-index branch tips in installed.php and installed.json.
 */

$expected = [
    'phpstan/phpstan' => [
        'alias' => '2.2.99',
        'reference' => '63f848a4b1dbe2f2cb87acb3ff32596661e45417',
    ],
    'phpstan/extension-installer' => [
        'alias' => '1.4.3',
        'reference' => '126008878950b0289c189502695ac6d96b73efb7',
    ],
];

/**
 * @param array<string, array{alias: string, reference: string}> $map
 */
function assert_cluster_pins(array $map, string $installedPhp, string $installedJson): bool
{
  $failed = false;

  $installedPhpPath = $installedPhp;
  if (!is_readable($installedPhpPath)) {
      fwrite(STDERR, "check-cluster-pins: vendor/composer/installed.php not found; run composer update first.\n");
      return false;
  }

  if (!is_readable($installedJson)) {
      fwrite(STDERR, "check-cluster-pins: vendor/composer/installed.json not found; run composer update first.\n");
      return false;
  }

  /** @var array{versions: array<string, array{reference?: string, aliases?: list<string>}>} $installed */
  $installed = require $installedPhpPath;
  $versions = $installed['versions'] ?? [];

  $raw = file_get_contents($installedJson);
  if ($raw === false) {
      fwrite(STDERR, "check-cluster-pins: could not read {$installedJson}.\n");
      return false;
  }

  /** @var array{packages?: list<array<string, mixed>>, 'packages-dev'?: list<array<string, mixed>>} $jsonData */
  $jsonData = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
  $jsonPackages = array_merge($jsonData['packages'] ?? [], $jsonData['packages-dev'] ?? []);

  /** @var array<string, string> $jsonReferences */
  $jsonReferences = [];
  foreach ($jsonPackages as $package) {
      if (!isset($package['name'], $package['source']['reference'])) {
          continue;
      }
      $jsonReferences[(string) $package['name']] = strtolower((string) $package['source']['reference']);
  }

  foreach ($map as $name => $want) {
      if (!isset($versions[$name])) {
          fwrite(STDERR, sprintf("check-cluster-pins: %s is not installed (installed.php).\n", $name));
          $failed = true;
          continue;
      }

      $pkg = $versions[$name];
      $aliases = $pkg['aliases'] ?? [];
      if (!in_array($want['alias'], $aliases, true)) {
          fwrite(STDERR, sprintf(
              "check-cluster-pins: %s alias mismatch in installed.php (expected %s in aliases %s).\n",
              $name,
              $want['alias'],
              json_encode($aliases)
          ));
          $failed = true;
      }

      $reference = $pkg['reference'] ?? null;
      if (!is_string($reference) || $reference === '') {
          fwrite(STDERR, sprintf("check-cluster-pins: %s has no reference in installed.php.\n", $name));
          $failed = true;
      } elseif (!hash_equals($want['reference'], $reference)) {
          fwrite(STDERR, sprintf(
              "check-cluster-pins: %s reference mismatch in installed.php (expected %s, got %s).\n",
              $name,
              $want['reference'],
              $reference
          ));
          $failed = true;
      }

      if (!isset($jsonReferences[$name])) {
          fwrite(STDERR, sprintf("check-cluster-pins: %s is not installed (installed.json).\n", $name));
          $failed = true;
          continue;
      }

      $jsonReference = $jsonReferences[$name];
      $wantLower = strtolower($want['reference']);
      if ($jsonReference !== $wantLower) {
          fwrite(STDERR, sprintf(
              "check-cluster-pins: %s source.reference mismatch in installed.json (expected %s, got %s).\n",
              $name,
              $want['reference'],
              $jsonReference
          ));
          $failed = true;
      }
  }

  return !$failed;
}

/**
 * @param array<string, array{alias: string, reference: string}> $baseExpected
 */
function run_wrong_sha_demos(array $baseExpected, string $installedPhp, string $installedJson): bool
{
    $allPassed = true;

    $mapPhpstan = $baseExpected;
    $mapPhpstan['phpstan/phpstan']['reference'] = str_repeat('0', 40);
    if (assert_cluster_pins($mapPhpstan, $installedPhp, $installedJson)) {
        fwrite(STDERR, "check-cluster-pins: wrong-SHA demo for installed.php did not fail.\n");
        $allPassed = false;
    } else {
        fwrite(STDOUT, "check-cluster-pins: wrong-SHA demo for installed.php failed as expected.\n");
    }

    $mapInstaller = $baseExpected;
    $mapInstaller['phpstan/extension-installer']['reference'] = str_repeat('f', 40);
    if (assert_cluster_pins($mapInstaller, $installedPhp, $installedJson)) {
        fwrite(STDERR, "check-cluster-pins: wrong-SHA demo for installed.json path did not fail.\n");
        $allPassed = false;
    } else {
        fwrite(STDOUT, "check-cluster-pins: wrong-SHA demo for installed.json failed as expected.\n");
    }

    return $allPassed;
}

$root = dirname(__DIR__);
$installedPhp = $root . '/vendor/composer/installed.php';
$installedJson = $root . '/vendor/composer/installed.json';

$argv = $_SERVER['argv'] ?? [];
$selfTest = in_array('--self-test-wrong-sha', $argv, true);

if ($selfTest) {
    if (!assert_cluster_pins($expected, $installedPhp, $installedJson)) {
        fwrite(STDERR, "check-cluster-pins: cannot run wrong-SHA demo before a successful pin check.\n");
        exit(1);
    }
    if (!run_wrong_sha_demos($expected, $installedPhp, $installedJson)) {
        exit(1);
    }
    fwrite(STDOUT, "check-cluster-pins: wrong-SHA self-test passed.\n");
    exit(0);
}

if (!assert_cluster_pins($expected, $installedPhp, $installedJson)) {
    exit(1);
}

fwrite(STDOUT, "check-cluster-pins: all cluster packages match expected SHAs and aliases.\n");
exit(0);
