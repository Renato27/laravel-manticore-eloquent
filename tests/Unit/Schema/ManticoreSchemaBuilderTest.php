<?php

use Illuminate\Database\Connection;
use ManticoreEloquent\Database\Schema\ManticoreSchemaBuilder;

/**
 * hasTable()/getColumnListing() run real SHOW TABLES / DESC (covered by the integration
 * suite). dropAllTables() is verified here with a mocked connection so the test never
 * touches a live server.
 */

afterEach(fn () => Mockery::close());

it('drops every index reported by SHOW TABLES', function () {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getSchemaGrammar')->andReturnNull();
    $connection->shouldReceive('select')->with('SHOW TABLES')->andReturn([
        ['Index' => 'alpha_rt', 'Type' => 'rt'],
        ['Index' => 'beta_rt', 'Type' => 'rt'],
    ]);
    $connection->shouldReceive('statement')->once()->with('DROP TABLE IF EXISTS alpha_rt');
    $connection->shouldReceive('statement')->once()->with('DROP TABLE IF EXISTS beta_rt');

    (new ManticoreSchemaBuilder($connection))->dropAllTables();
});
