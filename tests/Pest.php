<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
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
    return $sql.' limit 2147483646 OPTION max_matches=2147483646';
}

/**
 * Whether the server has float_vector_array, which Manticore added in 29.2.0 and first released
 * in 29.9.0. The oldest server CI tests has no such type.
 */
function supportsFloatVectorArray(): bool
{
    static $supported = null;

    if ($supported === null) {
        $db = DB::connection('manticore');

        try {
            $db->statement('CREATE TABLE IF NOT EXISTS float_vector_array_probe (v float_vector_array)');
            $db->statement('DROP TABLE IF EXISTS float_vector_array_probe');
            $supported = true;
        } catch (QueryException) {
            $supported = false;
        }
    }

    return $supported;
}
