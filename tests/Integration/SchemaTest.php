<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function manticoreSchema(): Builder
{
    return Schema::connection('manticore');
}

beforeEach(function () {
    try {
        DB::connection('manticore')->getPdo();
    } catch (Throwable $e) {
        $this->markTestSkipped('Manticore not reachable on the MySQL port: '.$e->getMessage());
    }

    foreach (['schema_a', 'schema_b'] as $table) {
        manticoreSchema()->dropIfExists($table);
    }
});

afterEach(function () {
    foreach (['schema_a', 'schema_b'] as $table) {
        try {
            manticoreSchema()->dropIfExists($table);
        } catch (Throwable $e) {
            // Nothing to clean up when the server is not reachable.
        }
    }
});

it('maps every Blueprint column type to one the server takes', function (Closure $column, string $type) {
    manticoreSchema()->create('schema_a', fn (Blueprint $table) => $column($table));

    $columns = array_column(manticoreSchema()->getColumns('schema_a'), 'type', 'name');

    expect($columns['c'])->toBe($type);
})->with([
    'string' => [fn ($t) => $t->string('c'), 'string'],
    'char' => [fn ($t) => $t->char('c'), 'string'],
    'ulid' => [fn ($t) => $t->ulid('c'), 'string'],
    'enum' => [fn ($t) => $t->enum('c', ['a', 'b']), 'string'],
    'set' => [fn ($t) => $t->set('c', ['a', 'b']), 'string'],
    'ipAddress' => [fn ($t) => $t->ipAddress('c'), 'string'],
    'macAddress' => [fn ($t) => $t->macAddress('c'), 'string'],
    'time' => [fn ($t) => $t->time('c'), 'string'],
    'timeTz' => [fn ($t) => $t->timeTz('c'), 'string'],
    'text' => [fn ($t) => $t->text('c'), 'text'],
    'tinyText' => [fn ($t) => $t->tinyText('c'), 'text'],
    'mediumText' => [fn ($t) => $t->mediumText('c'), 'text'],
    'longText' => [fn ($t) => $t->longText('c'), 'text'],
    'integer' => [fn ($t) => $t->integer('c'), 'uint'],
    'tinyInteger' => [fn ($t) => $t->tinyInteger('c'), 'uint'],
    'smallInteger' => [fn ($t) => $t->smallInteger('c'), 'uint'],
    'mediumInteger' => [fn ($t) => $t->mediumInteger('c'), 'uint'],
    'unsignedInteger' => [fn ($t) => $t->unsignedInteger('c'), 'uint'],
    'unsignedTiny' => [fn ($t) => $t->unsignedTinyInteger('c'), 'uint'],
    'year' => [fn ($t) => $t->year('c'), 'uint'],
    'bigInteger' => [fn ($t) => $t->bigInteger('c'), 'bigint'],
    'unsignedBigInteger' => [fn ($t) => $t->unsignedBigInteger('c'), 'bigint'],
    'foreignId' => [fn ($t) => $t->foreignId('c'), 'bigint'],
    'float' => [fn ($t) => $t->float('c'), 'float'],
    'double' => [fn ($t) => $t->double('c'), 'float'],
    'decimal' => [fn ($t) => $t->decimal('c'), 'float'],
    'boolean' => [fn ($t) => $t->boolean('c'), 'bool'],
    'json' => [fn ($t) => $t->json('c'), 'json'],
    'jsonb' => [fn ($t) => $t->jsonb('c'), 'json'],
    'timestamp' => [fn ($t) => $t->timestamp('c'), 'timestamp'],
    'timestampTz' => [fn ($t) => $t->timestampTz('c'), 'timestamp'],
    'dateTime' => [fn ($t) => $t->dateTime('c'), 'timestamp'],
    'dateTimeTz' => [fn ($t) => $t->dateTimeTz('c'), 'timestamp'],
    'date' => [fn ($t) => $t->date('c'), 'timestamp'],
    'softDeletes' => [fn ($t) => $t->softDeletes('c'), 'timestamp'],
]);

it('names the column when a type has no equivalent', function (Closure $column, string $type) {
    expect(fn () => manticoreSchema()->create('schema_a', fn (Blueprint $table) => $column($table)))
        ->toThrow(LogicException::class, "Manticore has no column type for [{$type}], used by column [c]");
})->with([
    'binary' => [fn ($t) => $t->binary('c'), 'binary'],
    'geometry' => [fn ($t) => $t->geometry('c'), 'geometry'],
]);

it('renames a table', function () {
    manticoreSchema()->create('schema_a', fn (Blueprint $table) => $table->integer('n'));

    manticoreSchema()->rename('schema_a', 'schema_b');

    expect(manticoreSchema()->hasTable('schema_a'))->toBeFalse()
        ->and(manticoreSchema()->hasTable('schema_b'))->toBeTrue();
});

it('lists the tables', function () {
    manticoreSchema()->create('schema_a', fn (Blueprint $table) => $table->integer('n'));

    $tables = array_column(manticoreSchema()->getTables(), null, 'name');

    expect($tables)->toHaveKey('schema_a')
        ->and($tables['schema_a']['engine'])->toBe('rt');
});

it('changes an integer column to bigint, the one change the manual documents', function () {
    manticoreSchema()->create('schema_a', fn (Blueprint $table) => $table->integer('n'));

    manticoreSchema()->table('schema_a', fn (Blueprint $table) => $table->bigInteger('n')->change());

    expect(array_column(manticoreSchema()->getColumns('schema_a'), 'type', 'name')['n'])->toBe('bigint');
});

it('refuses any other change with a clear error', function () {
    manticoreSchema()->create('schema_a', fn (Blueprint $table) => $table->string('s'));

    manticoreSchema()->table('schema_a', fn (Blueprint $table) => $table->text('s')->change());
})->throws(LogicException::class, 'Manticore can only change a column to bigInteger');

it('adds several columns at once', function () {
    manticoreSchema()->create('schema_a', fn (Blueprint $table) => $table->string('a'));

    manticoreSchema()->table('schema_a', function (Blueprint $table) {
        $table->integer('b');
        $table->string('c');
        $table->morphs('owner');
    });

    expect(array_column(manticoreSchema()->getColumns('schema_a'), 'type', 'name'))->toMatchArray(['a' => 'string', 'b' => 'uint', 'c' => 'string', 'owner_type' => 'string', 'owner_id' => 'bigint']);
});

it('creates a table with nothing but the id', function () {
    manticoreSchema()->create('schema_a', fn (Blueprint $table) => $table->id());

    expect(array_column(manticoreSchema()->getColumns('schema_a'), 'name'))->toBe(['id']);
});

it('runs a usual migration, leaving out what Manticore does not declare', function () {
    manticoreSchema()->create('schema_b', fn (Blueprint $table) => $table->integer('n'));

    manticoreSchema()->create('schema_a', function (Blueprint $table) {
        $table->id();
        $table->string('slug')->nullable()->default('x')->comment('c')->index();
        $table->string('code')->unique();
        $table->foreignId('b_id')->constrained('schema_b');
        $table->text('body');
        $table->fullText('body');
        $table->timestamps();
        $table->softDeletes();
        $table->rememberToken();
    });

    expect(array_column(manticoreSchema()->getColumns('schema_a'), 'type', 'name'))->toMatchArray([
        'slug' => 'string', 'code' => 'string', 'b_id' => 'bigint', 'body' => 'text',
        'created_at' => 'timestamp', 'updated_at' => 'timestamp', 'deleted_at' => 'timestamp', 'remember_token' => 'string',
    ]);

    manticoreSchema()->table('schema_a', function (Blueprint $table) {
        $table->dropForeign(['b_id']);
        $table->dropIndex(['slug']);
        $table->dropUnique(['code']);
        $table->dropFullText(['body']);
        $table->dropColumn('code');
    });

    expect(manticoreSchema()->hasColumn('schema_a', 'code'))->toBeFalse();
});

it('answers about what Manticore does not have', function () {
    manticoreSchema()->create('schema_a', fn (Blueprint $table) => $table->integer('n'));

    expect(manticoreSchema()->getForeignKeys('schema_a'))->toBe([])
        ->and(manticoreSchema()->getViews())->toBe([])
        ->and(manticoreSchema()->withoutForeignKeyConstraints(fn () => 'ran'))->toBe('ran');
});

it('reads the rest of what the schema builder offers', function () {
    manticoreSchema()->create('schema_a', fn (Blueprint $table) => $table->integer('n'));

    expect(manticoreSchema()->hasColumn('schema_a', 'n'))->toBeTrue()
        ->and(manticoreSchema()->hasColumns('schema_a', ['n', 'id']))->toBeTrue()
        ->and(manticoreSchema()->getColumnType('schema_a', 'n'))->toBe('uint')
        ->and(manticoreSchema()->hasIndex('schema_a', 'primary'))->toBeTrue();
});

it('refuses the vector type of Laravel, pointing nowhere near the server', function () {
    manticoreSchema()->create('schema_a', fn (Blueprint $table) => $table->vector('v', 3));
})->throws(Exception::class);
