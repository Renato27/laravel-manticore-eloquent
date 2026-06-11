<?php

use Tests\TestCase;

/*
| Unit tests are pure (offline) SQL-compilation tests and use the default PHPUnit
| TestCase. Feature/Integration tests boot a Laravel app via Testbench.
*/

uses(TestCase::class)->in('Feature', 'Integration');
