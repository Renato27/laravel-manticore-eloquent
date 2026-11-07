<?php

namespace ManticoreEloquent;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\ServiceProvider;
use ManticoreEloquent\Database\ManticoreConnection;
use ManticoreEloquent\Database\ManticoreConnector;

class ManticoreEloquentServiceProvider extends ServiceProvider
{
    /**
     * Merge the package config and register the Manticore database driver.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/manticore-eloquent.php', 'manticore-eloquent');

        $this->registerManticoreDriver();
    }

    /**
     * Publish the config and wire up the connection and the macros.
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/manticore-eloquent.php' => config_path('manticore-eloquent.php'),
        ], 'config');

        $this->registerManticoreDatabaseConnection();
        $this->registerBlueprintMacros();
        $this->registerEloquentMacros();
    }

    /**
     * getWithFacets() on Eloquent, since the query builder knows nothing of scopes and models.
     */
    protected function registerEloquentMacros(): void
    {
        EloquentBuilder::macro('getWithFacets', function (array $columns = ['*']) {
            $result = $this->toBase()->getWithFacets($columns);
            $result['rows'] = $this->hydrate($result['rows']->all());

            return $result;
        });
    }

    /**
     * Register the "manticore" database driver (driver => 'manticore').
     */
    protected function registerManticoreDriver(): void
    {
        $this->app->bind('db.connector.manticore', function () {
            return new ManticoreConnector;
        });

        Connection::resolverFor('manticore', function ($connection, $database, $prefix, $config) {
            return new ManticoreConnection($connection, $database, $prefix, $config);
        });
    }

    /**
     * Auto-derive the database connection from config/manticore-eloquent.php so users
     * don't have to edit config/database.php. Respects a manually-defined connection.
     */
    protected function registerManticoreDatabaseConnection(): void
    {
        $name = (string) config('manticore-eloquent.connection', 'manticore');

        if (config("database.connections.{$name}") !== null) {
            return;
        }

        config([
            "database.connections.{$name}" => [
                'driver' => 'manticore',
                'host' => config('manticore-eloquent.host', '127.0.0.1'),
                'port' => config('manticore-eloquent.port', 9306),
                'database' => null,
                'username' => config('manticore-eloquent.username', null),
                'password' => config('manticore-eloquent.password', null),
                'engine' => config('manticore-eloquent.engine', null),
                'default_limit' => config('manticore-eloquent.default_limit', null),
                'prefix' => '',
            ],
        ]);
    }

    /**
     * Register Manticore-specific column types on the schema Blueprint, so migrations
     * can declare them the Laravel way.
     */
    protected function registerBlueprintMacros(): void
    {
        if (! Blueprint::hasMacro('mva')) {
            Blueprint::macro('mva', function (string $column) {
                return $this->addColumn('mva', $column);
            });
        }

        if (! Blueprint::hasMacro('mva64')) {
            Blueprint::macro('mva64', function (string $column) {
                return $this->addColumn('mva64', $column);
            });
        }

        if (! Blueprint::hasMacro('floatVector')) {
            Blueprint::macro('floatVector', function (string $column, ?int $dims = null, string $knnType = 'hnsw', string $similarity = 'L2', array $knnOptions = []) {
                return $this->addColumn('floatVector', $column, compact('dims', 'knnType', 'similarity', 'knnOptions'));
            });
        }

        if (! Blueprint::hasMacro('manticoreOptions')) {
            Blueprint::macro('manticoreOptions', function (array $options) {
                return $this->addCommand('manticoreTableOptions', compact('options'));
            });
        }

        if (! Blueprint::hasMacro('minInfixLen')) {
            Blueprint::macro('minInfixLen', function (int $length) {
                return $this->manticoreOptions(['min_infix_len' => $length]);
            });
        }

        if (! Blueprint::hasMacro('morphology')) {
            Blueprint::macro('morphology', function (string $morphology) {
                return $this->manticoreOptions(['morphology' => $morphology]);
            });
        }
    }
}
