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
        (object) ['Table' => 'alpha_rt', 'Type' => 'rt'],
        (object) ['Table' => 'beta_rt', 'Type' => 'rt'],
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

it('describes the columns of a table, through its prefix', function () {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getSchemaGrammar')->andReturnNull();
    $connection->shouldReceive('getTablePrefix')->andReturn('pre_');
    $connection->shouldReceive('select')->with('DESC pre_docs')->andReturn([
        (object) ['Field' => 'id', 'Type' => 'bigint', 'Properties' => ''],
        (object) ['Field' => 'title', 'Type' => 'text', 'Properties' => 'indexed stored'],
    ]);

    $builder = new ManticoreSchemaBuilder($connection);

    expect($builder->getColumns('docs'))->toBe([
        ['name' => 'id', 'type_name' => 'bigint', 'type' => 'bigint', 'collation' => null, 'nullable' => false, 'default' => null, 'auto_increment' => true, 'comment' => null, 'generation' => null],
        ['name' => 'title', 'type_name' => 'text', 'type' => 'text', 'collation' => null, 'nullable' => false, 'default' => null, 'auto_increment' => false, 'comment' => 'indexed stored', 'generation' => null],
    ])->and($builder->getColumnListing('docs'))->toBe(['id', 'title']);
});

it('lists the tables and finds one through its prefix', function () {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getSchemaGrammar')->andReturnNull();
    $connection->shouldReceive('getTablePrefix')->andReturn('pre_');
    $connection->shouldReceive('select')->with('SHOW TABLES')->andReturn([(object) ['Table' => 'pre_docs', 'Type' => 'rt']]);

    $builder = new ManticoreSchemaBuilder($connection);

    expect($builder->getTables())->toBe([
        ['name' => 'pre_docs', 'schema' => null, 'size' => null, 'comment' => null, 'collation' => null, 'engine' => 'rt'],
    ])->and($builder->hasTable('docs'))->toBeTrue()
        ->and($builder->hasTable('DOCS'))->toBeTrue()
        ->and($builder->hasTable('pre_docs'))->toBeFalse()
        ->and($builder->hasTable('other'))->toBeFalse();
});

it('has no foreign keys to turn on or off', function () {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getSchemaGrammar')->andReturnNull();

    $builder = new ManticoreSchemaBuilder($connection);

    expect($builder->disableForeignKeyConstraints())->toBeTrue()
        ->and($builder->enableForeignKeyConstraints())->toBeTrue();
});
