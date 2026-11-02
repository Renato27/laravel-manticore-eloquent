<?php

use Carbon\CarbonPeriod;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ManticoreEloquent\Eloquent\ManticoreModel;

/**
 * Manticore is strict about types: a quoted number is rejected by a filter and ignored by an
 * UPDATE, and an unquoted one is dropped by a string column. A value takes its column's type.
 */
class TypedDocument extends ManticoreModel
{
    protected $table = 'typed_docs';

    protected $guarded = [];

    public $timestamps = false;
}

function typedTable(): Builder
{
    return DB::connection('manticore')->table('typed_docs');
}

beforeEach(function () {
    try {
        DB::connection('manticore')->getPdo();
    } catch (Throwable $e) {
        $this->markTestSkipped('Manticore not reachable on the MySQL port: '.$e->getMessage());
    }

    $schema = Schema::connection('manticore');
    $schema->dropIfExists('typed_docs');
    $schema->create('typed_docs', function (Blueprint $table) {
        $table->integer('views');
        $table->bigInteger('total');
        $table->float('score');
        $table->boolean('published');
        $table->string('slug');
        $table->timestamp('seen_at');
    });

    typedTable()->insert([
        ['id' => 1, 'views' => 10, 'total' => 100, 'score' => 1.5, 'published' => 1, 'slug' => '10', 'seen_at' => 1000],
        ['id' => 2, 'views' => 20, 'total' => 200, 'score' => 5.5, 'published' => 0, 'slug' => 'b', 'seen_at' => 2000],
    ]);
});

afterEach(function () {
    try {
        Schema::connection('manticore')->dropIfExists('typed_docs');
    } catch (Throwable $e) {
        // Nothing to clean up when the server is not reachable.
    }
});

it('finds by a numeric string id', function () {
    expect(TypedDocument::find('1')->id)->toBe(1)
        ->and(TypedDocument::whereKey('2')->first()->id)->toBe(2)
        ->and(TypedDocument::findMany(['1', '2']))->toHaveCount(2);
});

it('resolves a route binding', function () {
    expect((new TypedDocument)->resolveRouteBinding('1')->id)->toBe(1);
});

it('filters numeric columns with numeric strings', function () {
    expect(typedTable()->where('views', '10')->pluck('id')->all())->toBe([1])
        ->and(typedTable()->where('total', '>', '100')->pluck('id')->all())->toBe([2])
        ->and(typedTable()->where('published', '1')->pluck('id')->all())->toBe([1])
        ->and(typedTable()->whereIn('views', ['10', '20'])->count())->toBe(2)
        ->and(typedTable()->whereNotIn('views', ['10'])->pluck('id')->all())->toBe([2])
        ->and(typedTable()->whereBetween('views', ['15', '25'])->pluck('id')->all())->toBe([2])
        ->and(typedTable()->where(['views' => '10'])->pluck('id')->all())->toBe([1]);
});

it('filters with floats', function () {
    expect(typedTable()->where('score', '>', 2.5)->pluck('id')->all())->toBe([2])
        ->and(typedTable()->where('score', '>', '2.5')->pluck('id')->all())->toBe([2])
        ->and(typedTable()->whereBetween('score', [1.0, 2.0])->pluck('id')->all())->toBe([1])
        ->and(typedTable()->where(fn ($q) => $q->where('score', '<', 2.5))->pluck('id')->all())->toBe([1]);
});

it('updates a float, which a quoted value would silently skip', function () {
    typedTable()->where('id', 1)->update(['score' => 3.25]);

    expect((float) typedTable()->where('id', 1)->value('score'))->toBe(3.25);

    typedTable()->where('id', 1)->update(['score' => '4.5']);

    expect((float) typedTable()->where('id', 1)->value('score'))->toBe(4.5);
});

it('updates numeric columns from strings, which the server would silently skip', function () {
    typedTable()->where('id', 1)->update(['views' => '33', 'total' => '44', 'published' => '0', 'seen_at' => '5000']);

    $row = typedTable()->where('id', 1)->first();

    expect((int) $row->views)->toBe(33)
        ->and((int) $row->total)->toBe(44)
        ->and((int) $row->published)->toBe(0)
        ->and((int) $row->seen_at)->toBe(5000);
});

it('keeps a numeric string in a string column', function () {
    expect(typedTable()->where('slug', '10')->pluck('id')->all())->toBe([1])
        ->and(typedTable()->where('slug', 10)->pluck('id')->all())->toBe([1]);

    typedTable()->where('id', 2)->update(['slug' => '20']);

    expect(typedTable()->where('id', 2)->value('slug'))->toBe('20');
});

it('writes a number into a string column, which the server would silently drop', function () {
    typedTable()->where('id', 2)->update(['slug' => 30]);

    expect(typedTable()->where('id', 2)->value('slug'))->toBe('30');

    TypedDocument::create(['id' => 3, 'slug' => 40]);

    expect(typedTable()->where('id', 3)->value('slug'))->toBe('40');
});

it('writes a date string into a timestamp column, which the server would store as garbage', function () {
    typedTable()->insert(['id' => 3, 'seen_at' => '2026-01-02 00:00:00']);
    typedTable()->where('id', 2)->update(['seen_at' => '2026-01-03 00:00:00']);

    expect((int) typedTable()->where('id', 3)->value('seen_at'))->toBe(strtotime('2026-01-02 00:00:00'))
        ->and((int) typedTable()->where('id', 2)->value('seen_at'))->toBe(strtotime('2026-01-03 00:00:00'))
        ->and(typedTable()->where('seen_at', '>', '2026-01-02 12:00:00')->pluck('id')->all())->toBe([2]);
});

it('inserts numeric strings and replaces with them', function () {
    typedTable()->insert(['id' => '3', 'views' => '30', 'score' => '2.5', 'seen_at' => '3000']);
    typedTable()->replace(['id' => 2, 'views' => '21', 'seen_at' => '2100']);

    expect((int) typedTable()->where('id', 3)->value('views'))->toBe(30)
        ->and((int) typedTable()->where('id', 3)->value('seen_at'))->toBe(3000)
        ->and((float) typedTable()->where('id', 3)->value('score'))->toBe(2.5)
        ->and((int) typedTable()->where('id', 2)->value('views'))->toBe(21)
        ->and((int) typedTable()->where('id', 2)->value('seen_at'))->toBe(2100);
});

it('fails loudly on a word in a numeric column', function () {
    typedTable()->where('views', 'abc')->get();
})->throws(QueryException::class);

it('sees a column added in the same process', function () {
    expect(typedTable()->where('views', '10')->count())->toBe(1);

    Schema::connection('manticore')->table('typed_docs', fn (Blueprint $table) => $table->integer('extra'));
    typedTable()->where('id', 1)->update(['extra' => '7']);

    expect(typedTable()->where('extra', '7')->pluck('id')->all())->toBe([1]);
});

it('sees a table recreated by hand', function () {
    expect(typedTable()->where('slug', '10')->count())->toBe(1);

    DB::connection('manticore')->statement('DROP TABLE typed_docs');
    DB::connection('manticore')->statement('CREATE TABLE typed_docs (slug int)');
    typedTable()->insert(['id' => 1, 'slug' => 10]);

    expect(typedTable()->where('slug', '10')->count())->toBe(1);
});

it('takes dates as a period in whereBetween', function () {
    $period = CarbonPeriod::create('1970-01-01 00:00:00', '1 minute', '1970-01-01 00:40:00');

    expect(typedTable()->whereBetween('seen_at', $period)->count())->toBe(2);
});

it('asks DESC only once per table', function () {
    $connection = DB::connection('manticore');
    $connection->statement('ALTER TABLE typed_docs ADD COLUMN extra int');
    $connection->flushQueryLog();
    $connection->enableQueryLog();

    typedTable()->where('views', '10')->get();
    typedTable()->where('total', '100')->get();

    $describes = array_filter($connection->getQueryLog(), fn ($q) => str_starts_with($q['query'], 'DESC'));

    expect($describes)->toHaveCount(1);
});

it('leaves a raw table, an alias and an unknown table alone', function () {
    expect(DB::connection('manticore')->table('typed_docs as t')->where('t.views', '10')->pluck('id')->all())->toBe([1])
        ->and(DB::connection('manticore')->query()->fromRaw('typed_docs')->where('views', 10)->pluck('id')->all())->toBe([1])
        ->and(fn () => DB::connection('manticore')->table('no_such_table')->where('a', '1')->get())
        ->toThrow(QueryException::class, 'no_such_table');
});

it('leaves a table list and a prefix to the server', function () {
    config(['database.connections.manticore_prefixed' => ['prefix' => 'typed_'] + config('database.connections.manticore')]);

    expect(DB::connection('manticore_prefixed')->table('docs')->where('views', '10')->pluck('id')->all())->toBe([1]);
});

it('rounds a decimal written to an integer column, which UPDATE would store as garbage', function () {
    typedTable()->where('id', 1)->update(['views' => 1.7, 'total' => '2.4']);

    expect((int) typedTable()->where('id', 1)->value('views'))->toBe(2)
        ->and((int) typedTable()->where('id', 1)->value('total'))->toBe(2);

    typedTable()->insert(['id' => 3, 'views' => 3.6]);

    expect((int) typedTable()->where('id', 3)->value('views'))->toBe(4);
});

it('refuses a word written to a numeric column, which the server would store as 0 or skip', function (string $column, string $value) {
    expect(fn () => typedTable()->insert(['id' => 3, $column => $value]))
        ->toThrow(InvalidArgumentException::class, "Column [{$column}]")
        ->and(fn () => typedTable()->where('id', 1)->update([$column => $value]))
        ->toThrow(InvalidArgumentException::class, "Column [{$column}]")
        ->and(typedTable()->count())->toBe(2);
})->with([
    'an integer' => ['views', 'abc'],
    'a bigint' => ['total', 'abc'],
    'a float' => ['score', 'abc'],
    'a bool' => ['published', 'abc'],
    'a timestamp' => ['seen_at', 'not a date'],
    'an oversized number' => ['total', '99999999999999999999'],
]);

it('refuses an id the server would read as another number, or replace by a generated one', function (string $id) {
    typedTable()->insert(['id' => $id, 'views' => 1]);
})->with(['01KQZX', 'abc'])->throws(InvalidArgumentException::class, 'Column [id]');

it('keeps a word in a string, a text and a json column', function () {
    typedTable()->insert(['id' => 3, 'slug' => 'abc']);

    expect(typedTable()->where('id', 3)->value('slug'))->toBe('abc');
});

it('rounds a decimal written to a bool and a timestamp column too', function () {
    typedTable()->where('id', 1)->update(['published' => 0.2, 'seen_at' => 1000.7]);

    expect((int) typedTable()->where('id', 1)->value('published'))->toBe(0)
        ->and((int) typedTable()->where('id', 1)->value('seen_at'))->toBe(1001);
});

