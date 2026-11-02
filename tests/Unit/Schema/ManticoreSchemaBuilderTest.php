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

it('reports the primary key, knn and full-text indexes of a table', function () {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getSchemaGrammar')->andReturnNull();
    $connection->shouldReceive('getTablePrefix')->andReturn('pre_');
    $connection->shouldReceive('select')->with('DESC pre_docs')->andReturn([
        (object) ['Field' => 'id', 'Type' => 'bigint', 'Properties' => ''],
        (object) ['Field' => 'title', 'Type' => 'text', 'Properties' => 'indexed stored'],
        (object) ['Field' => 'body', 'Type' => 'text', 'Properties' => 'indexed'],
        (object) ['Field' => 'raw', 'Type' => 'text', 'Properties' => 'stored'],
        (object) ['Field' => 'tag', 'Type' => 'string', 'Properties' => ''],
        (object) ['Field' => 'embedding', 'Type' => 'float_vector', 'Properties' => 'knn'],
        (object) ['Field' => 'plain_vector', 'Type' => 'float_vector', 'Properties' => ''],
    ]);

    $indexes = (new ManticoreSchemaBuilder($connection))->getIndexes('docs');

    expect($indexes)->toBe([
        ['name' => 'primary', 'columns' => ['id'], 'type' => 'primary', 'unique' => true, 'primary' => true],
        ['name' => 'embedding_knn', 'columns' => ['embedding'], 'type' => 'knn', 'unique' => false, 'primary' => false],
        ['name' => 'fulltext', 'columns' => ['title', 'body'], 'type' => 'fulltext', 'unique' => false, 'primary' => false],
    ]);
});

it('reports only the primary key when a table has nothing else indexed', function () {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getSchemaGrammar')->andReturnNull();
    $connection->shouldReceive('getTablePrefix')->andReturn('');
    $connection->shouldReceive('select')->with('DESC docs')->andReturn([
        (object) ['Field' => 'tag', 'Type' => 'string', 'Properties' => ''],
    ]);

    expect((new ManticoreSchemaBuilder($connection))->getIndexes('docs'))
        ->toHaveCount(1)
        ->and((new ManticoreSchemaBuilder($connection))->getIndexes('docs')[0]['type'])->toBe('primary');
});
