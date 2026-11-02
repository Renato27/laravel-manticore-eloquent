<?php

use Tests\TestCase;

/*
| Unit tests are pure (offline) SQL-compilation tests and use the default PHPUnit
| TestCase. Feature/Integration tests boot a Laravel app via Testbench.
*/

uses(TestCase::class)->in('Feature', 'Integration');

/**
 * What the grammar appends to a select without a limit, since Manticore returns 20 rows
 * by default.
 */
function unlimited(string $sql): string
{
    return $sql . ' limit 2147483646 OPTION max_matches=2147483646';
}
