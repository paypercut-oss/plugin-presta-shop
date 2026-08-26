<?php

/**
 * Paypercut - Test runner
 *
 * Usage: php tests/run.php
 *
 * Also lints every PHP file in the module, so a syntax error in a file no test
 * loads still fails the build.
 *
 * @author    Paypercut <support@paypercut.io>
 * @copyright Paypercut
 * @license   https://mit-license.org ( MIT )
 */

$root = dirname(__FILE__) . '/..';
$failed = 0;

// ── Lint every PHP file ──
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

$linted = 0;

foreach ($iterator as $file) {
    $path = $file->getPathname();

    if (substr($path, -4) !== '.php' || strpos($path, '/.git/') !== false) {
        continue;
    }

    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1', $output, $status);

    if ($status !== 0) {
        echo "LINT FAILED: " . $path . "\n" . implode("\n", $output) . "\n";
        $failed = 1;
    }

    $output = array();
    ++$linted;
}

echo $linted . " files linted\n";

// ── Run the suites ──
require_once dirname(__FILE__) . '/bootstrap.php';

foreach (array(
    'EnvironmentTest.php',
    'EventTest.php',
    'DenyAssertionTest.php',
    'QueueTest.php',
    'FlusherDecideTest.php',
    'DisclosureTest.php',
    'EventCatalogTest.php',
) as $suite) {
    require_once dirname(__FILE__) . '/' . $suite;
}

exit(Assert::report() + $failed);
