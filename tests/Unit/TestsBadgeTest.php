<?php

/**
 * The script CI runs to turn a JUnit report into the JSON behind the README's tests badge.
 */

/**
 * @return array{schemaVersion: int, label: string, message: string, color: string}
 */
function testsBadgeFor(?string $report): array
{
    $input = tempnam(sys_get_temp_dir(), 'junit');
    $output = tempnam(sys_get_temp_dir(), 'badge');

    if ($report === null) {
        unlink($input);
    } else {
        file_put_contents($input, $report);
    }

    exec(
        implode(' ', array_map('escapeshellarg', [PHP_BINARY, dirname(__DIR__, 2).'/.github/scripts/tests-badge.php', $input, $output])),
        $lines,
        $status
    );

    expect($status)->toBe(0);

    $badge = json_decode((string) file_get_contents($output), true);

    array_map('unlink', array_filter([$input, $output], 'is_file'));

    return $badge;
}

function junitReport(int $tests, int $failures = 0, int $errors = 0, int $skipped = 0): string
{
    return <<<XML
    <?xml version="1.0" encoding="UTF-8"?>
    <testsuites>
      <testsuite name="all" tests="{$tests}" assertions="1" errors="{$errors}" failures="{$failures}" skipped="{$skipped}" time="1.0">
        <testsuite name="Unit" tests="1" assertions="1" errors="0" failures="0" skipped="0" time="0.1"/>
      </testsuite>
    </testsuites>
    XML;
}

it('writes a badge the way shields.io reads an endpoint', function () {
    expect(testsBadgeFor(junitReport(213)))->toBe([
        'schemaVersion' => 1,
        'label' => 'tests',
        'message' => '100% (213/213)',
        'color' => 'brightgreen',
    ]);
});

it('counts failures, errors and skipped tests against the percentage', function (array $counts, string $message) {
    expect(testsBadgeFor(junitReport(...$counts)))
        ->message->toBe($message)
        ->color->toBe('red');
})->with([
    'a failure' => [[200, 10, 0, 0], '95% (190/200)'],
    'an error' => [[200, 0, 10, 0], '95% (190/200)'],
    'skipped' => [[200, 0, 0, 50], '75% (150/200)'],
    'all of them' => [[200, 10, 10, 10], '85% (170/200)'],
    'one decimal' => [[200, 1, 0, 0], '99.5% (199/200)'],
    'none passing' => [[4, 4, 0, 0], '0% (0/4)'],
]);

it('never rounds a failing suite up to 100%', function () {
    expect(testsBadgeFor(junitReport(5000, 1)))
        ->message->toBe('99.9% (4999/5000)')
        ->color->toBe('red');
});

it('reports no results rather than keeping the last percentage', function (?string $report) {
    expect(testsBadgeFor($report))
        ->message->toBe('no results')
        ->color->toBe('red');
})->with([
    'report missing' => [null],
    'report empty' => [''],
    'not xml' => ['Fatal error: out of memory'],
    'no test ran' => [junitReport(0)],
    'no testsuite' => ['<?xml version="1.0"?><testsuites/>'],
]);

it('publishes the badge where the README reads it', function () {
    $root = dirname(__DIR__, 2);
    $readme = urldecode((string) file_get_contents($root.'/README.md'));
    $workflow = (string) file_get_contents($root.'/.github/workflows/tests.yml');

    expect($readme)->toContain('raw.githubusercontent.com/Renato27/laravel-manticore-eloquent/badges/tests.json');
    expect($workflow)
        ->toContain('.github/scripts/tests-badge.php build/junit.xml')
        ->toContain('HEAD:badges')
        ->toContain('tests.json');
});
