<?php

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use ManticoreEloquent\Database\Schema\ManticoreSchemaBuilder;
use ManticoreEloquent\Database\Schema\ManticoreSchemaGrammar;

/**
 * Pure DDL-compilation tests for the Manticore schema grammar (offline).
 */

function schemaConnection(): Connection
{
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getConfig')->andReturnNull();
    $connection->shouldReceive('getTablePrefix')->andReturn('');
    $connection->shouldReceive('getSchemaGrammar')->andReturnUsing(
        fn () => new ManticoreSchemaGrammar($connection)
    );
    // Laravel 12 resolves column-precision defaults through the schema builder.
    $connection->shouldReceive('getSchemaBuilder')->andReturnUsing(
        fn () => new ManticoreSchemaBuilder($connection)
    );

    return $connection;
}

/**
 * Build a blueprint, run the caller's column definitions, and compile it to SQL in a way
 * that works across Laravel versions.
 *
 * Laravel 10/11: `new Blueprint($table)` then `toSql($connection, $grammar)`.
 * Laravel 12:    `new Blueprint($connection, $table)` then `toSql()` (the grammar is
 *                resolved from the connection). We detect which constructor the running
 *                framework expects via reflection so one test body exercises every version.
 *
 * @return array<int, string>
 */
function manticoreSchemaSql(string $table, callable $define): array
{
    $connection = schemaConnection();
    $grammar = new ManticoreSchemaGrammar($connection);

    $ctor = (new ReflectionClass(Blueprint::class))->getConstructor();
    $connectionFirst = $ctor && $ctor->getParameters()[0]->getName() === 'connection';

    if ($connectionFirst) {
        // Laravel 12+: connection-first constructor, grammar resolved from the connection.
        $blueprint = new Blueprint($connection, $table);
        $define($blueprint);

        return $blueprint->toSql();
    }

    // Laravel 10/11: table-first constructor, grammar passed into toSql().
    $blueprint = new Blueprint($table);
    $define($blueprint);

    return $blueprint->toSql($connection, $grammar);
}

afterEach(fn () => Mockery::close());

it('compiles a create table with mapped Manticore types', function () {
    $sql = manticoreSchemaSql('articles_rt', function (Blueprint $blueprint) {
        $blueprint->create();
        $blueprint->text('body');
        $blueprint->string('title');
        $blueprint->integer('views');
        $blueprint->bigInteger('author_id');
        $blueprint->float('score');
        $blueprint->boolean('published');
        $blueprint->json('meta');
        $blueprint->timestamp('created_at');
    })[0];

    expect($sql)->toContain('create table `articles_rt` (');
    expect($sql)->toContain('`body` text');
    expect($sql)->toContain('`title` string');
    expect($sql)->toContain('`views` integer');
    expect($sql)->toContain('`author_id` bigint');
    expect($sql)->toContain('`score` float');
    expect($sql)->toContain('`published` bool');
    expect($sql)->toContain('`meta` json');
    expect($sql)->toContain('`created_at` timestamp');
});

it('skips the implicit id column', function () {
    $sql = manticoreSchemaSql('articles_rt', function (Blueprint $blueprint) {
        $blueprint->create();
        $blueprint->integer('id');
        $blueprint->string('title');
    })[0];

    expect($sql)->not->toContain('`id`');
    expect($sql)->toContain('`title` string');
});

it('spells out indexed/stored options on a text column', function () {
    $sql = manticoreSchemaSql('docs_rt', function (Blueprint $blueprint) {
        $blueprint->create();
        $blueprint->text('plain');
        $blueprint->text('body')->indexed()->stored();
    })[0];

    expect($sql)->toContain('`plain` text,');
    expect($sql)->toContain('`body` text indexed stored');
});

it('compiles a float_vector column with knn options', function () {
    $sql = manticoreSchemaSql('docs_rt', function (Blueprint $blueprint) {
        $blueprint->create();
        $blueprint->addColumn('floatVector', 'embedding', [
            'dims'       => 384,
            'knnType'    => 'hnsw',
            'similarity' => 'L2',
        ]);
    })[0];

    expect($sql)->toContain("`embedding` float_vector knn_type='hnsw' knn_dims='384' hnsw_similarity='L2'");
});

it('compiles multi-value attributes', function () {
    $sql = manticoreSchemaSql('docs_rt', function (Blueprint $blueprint) {
        $blueprint->create();
        $blueprint->addColumn('mva', 'tag_ids');
        $blueprint->addColumn('mva64', 'big_ids');
    })[0];

    expect($sql)->toContain('`tag_ids` multi');
    expect($sql)->toContain('`big_ids` multi64');
});

it('applies the engine when set on the blueprint', function () {
    $sql = manticoreSchemaSql('docs_rt', function (Blueprint $blueprint) {
        $blueprint->create();
        $blueprint->engine = 'columnar';
        $blueprint->string('title');
    })[0];

    expect($sql)->toContain("engine='columnar'");
});

it('compiles drop table statements', function () {
    $drop = manticoreSchemaSql('articles_rt', fn (Blueprint $blueprint) => $blueprint->drop());
    expect($drop[0])->toBe('drop table `articles_rt`');

    $dropIfExists = manticoreSchemaSql('articles_rt', fn (Blueprint $blueprint) => $blueprint->dropIfExists());
    expect($dropIfExists[0])->toBe('drop table if exists `articles_rt`');
});

it('compiles add column as alter statements', function () {
    $statements = manticoreSchemaSql('articles_rt', function (Blueprint $blueprint) {
        $blueprint->string('subtitle');
        $blueprint->integer('rank');
    });

    expect($statements)->toContain('alter table `articles_rt` add column `subtitle` string');
    expect($statements)->toContain('alter table `articles_rt` add column `rank` integer');
});

it('maps the remaining column types to their Manticore equivalents', function () {
    $sql = manticoreSchemaSql('docs_rt', function (Blueprint $blueprint) {
        $blueprint->create();
        $blueprint->char('code');
        $blueprint->tinyInteger('flag');
        $blueprint->smallInteger('rank');
        $blueprint->mediumInteger('hits');
        $blueprint->double('ratio');
        $blueprint->decimal('price');
        $blueprint->jsonb('payload');
        $blueprint->dateTime('seen_at');
        $blueprint->date('day');
    })[0];

    expect($sql)->toContain('`code` string');
    expect($sql)->toContain('`flag` integer');
    expect($sql)->toContain('`rank` integer');
    expect($sql)->toContain('`hits` integer');
    expect($sql)->toContain('`ratio` float');
    expect($sql)->toContain('`price` float');
    expect($sql)->toContain('`payload` json');
    expect($sql)->toContain('`seen_at` timestamp');
    expect($sql)->toContain('`day` timestamp');
});

it('compiles drop column as an alter statement', function () {
    $statements = manticoreSchemaSql('articles_rt', fn (Blueprint $blueprint) => $blueprint->dropColumn('subtitle'));

    expect($statements)->toContain('alter table `articles_rt` drop column `subtitle`');
});

it('wraps identifiers in backticks but leaves * alone', function () {
    $grammar = new ManticoreSchemaGrammar(schemaConnection());

    expect($grammar->wrap('title'))->toBe('`title`');
    expect($grammar->wrap('*'))->toBe('*');
});
