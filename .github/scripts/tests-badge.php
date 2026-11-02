<?php

/**
 * Turns a JUnit report into the JSON shields.io reads for the README's tests badge.
 *
 *   php tests-badge.php build/junit.xml tests.json
 *
 * Failed, errored and skipped tests all count against the percentage, which rounds down so a
 * failing suite never shows 100%. A missing or empty report says so instead of leaving the last
 * percentage up. It always exits 0, because a broken run is exactly what the badge must show.
 */

[, $report, $output] = $argv;

$badge = ['message' => 'no results', 'color' => 'red'];

libxml_use_internal_errors(true);

$document = new DOMDocument;
$suite = $document->load($report) ? $document->getElementsByTagName('testsuite')->item(0) : null;

$tests = $suite ? (int) $suite->getAttribute('tests') : 0;

if ($tests > 0) {
    $passed = $tests
        - (int) $suite->getAttribute('failures')
        - (int) $suite->getAttribute('errors')
        - (int) $suite->getAttribute('skipped');

    $percentage = intdiv($passed * 1000, $tests) / 10;

    $badge = [
        'message' => "{$percentage}% ({$passed}/{$tests})",
        'color'   => $passed === $tests ? 'brightgreen' : 'red',
    ];
}

file_put_contents($output, json_encode(['schemaVersion' => 1, 'label' => 'tests'] + $badge, JSON_UNESCAPED_SLASHES) . "\n");
