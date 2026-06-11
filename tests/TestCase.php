<?php

namespace Tests;

use ManticoreEloquent\ManticoreEloquentServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            ManticoreEloquentServiceProvider::class,
        ];
    }
}
