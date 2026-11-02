<?php

use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ManticoreEloquent\Eloquent\ManticoreModel;

/**
 * Manticore takes INNER and LEFT JOIN (https://manual.manticoresearch.com/Searching/Joining) and
 * sub-selects in FROM (https://manual.manticoresearch.com/Searching/Sub-selects).
 */
function joinTable(string $table = 'join_a'): Builder
{
    return DB::connection('manticore')->table($table);
}

beforeEach(function () {
    try {
        DB::connection('manticore')->getPdo();
    } catch (Throwable $e) {
        $this->markTestSkipped('Manticore not reachable on the MySQL port: '.$e->getMessage());
    }

    $schema = Schema::connection('manticore');
    $schema->dropIfExists('join_a');
    $schema->dropIfExists('join_b');
    $schema->create('join_a', function (Blueprint $table) {
        $table->text('title');
        $table->string('name');
        $table->integer('level');
    });
    $schema->create('join_b', function (Blueprint $table) {
        $table->bigInteger('a_id');
        $table->string('note');
    });

    joinTable()->insert([
        ['id' => 1, 'title' => 'first', 'name' => 'one', 'level' => 9],
        ['id' => 2, 'title' => 'second', 'name' => 'two', 'level' => 5],
        ['id' => 3, 'title' => 'third', 'name' => 'three', 'level' => 5],
    ]);
    joinTable('join_b')->insert([
        ['id' => 11, 'a_id' => 1, 'note' => 'x'],
        ['id' => 12, 'a_id' => 2, 'note' => 'y'],
        ['id' => 13, 'a_id' => 2, 'note' => 'z'],
    ]);
});

afterEach(function () {
    foreach (['join_a', 'join_b'] as $table) {
        try {
            Schema::connection('manticore')->dropIfExists($table);
        } catch (Throwable) {
            // Nothing to clean up when the server is not reachable.
        }
    }
});

function joined(): Builder
{
    return joinTable()->join('join_b', 'join_a.id', '=', 'join_b.a_id');
}

it('runs an inner join and names the columns as MySQL would', function () {
    $rows = joined()->select('join_a.id', 'join_a.name', 'join_a.title', 'join_b.note')->where('join_b.note', 'x')->get();

    expect($rows)->toHaveCount(1)
        ->and((array) $rows->first())->toEqual(['id' => 1, 'name' => 'one', 'title' => 'first', 'note' => 'x']);
});

it('runs a left join and finds what has no match', function () {
    expect(joinTable()->leftJoin('join_b', 'join_a.id', '=', 'join_b.a_id')->get())->toHaveCount(4)
        ->and(joinTable()->leftJoin('join_b', 'join_a.id', '=', 'join_b.a_id')->count())->toBe(4)
        ->and(joinTable()->leftJoin('join_b', 'join_a.id', '=', 'join_b.a_id')->paginate(2)->total())->toBe(4)
        ->and(joinTable()->leftJoin('join_b', 'join_a.id', '=', 'join_b.a_id')->whereNull('join_b.note')->pluck('join_a.id')->all())->toBe([3]);
});

it('keeps the left table when both have a column', function () {
    expect(joined()->where('join_b.id', 11)->first()->id)->toBe(1);
});

it('returns every row without a limit, and respects one', function () {
    expect(joined()->get())->toHaveCount(3)
        ->and(joined()->limit(1)->get())->toHaveCount(1)
        ->and(joined()->count())->toBe(3)
        ->and(joined()->exists())->toBeTrue();
});

it('filters, orders and groups across both tables', function () {
    expect(joined()->where('join_b.a_id', '2')->orderBy('join_b.note', 'desc')->pluck('join_b.note')->all())->toBe(['z', 'y'])
        ->and(joined()->where('join_a.level', '5')->count())->toBe(2)
        ->and(joined()->whereIn('join_b.note', ['x', 'y'])->orderBy('join_a.id', 'desc')->pluck('join_a.id')->all())->toBe([2, 1])
        ->and(joined()->selectRaw('level, count(*) as total')->groupBy('level')->orderBy('level')->pluck('total', 'level')->all())->toEqual([5 => 2, 9 => 1]);
});

it('refuses a join the server would run as an inner join', function (Closure $query) {
    expect($query)->toThrow(LogicException::class, 'Manticore only has inner and left joins');
})->with([
    'right' => [fn () => joinTable()->rightJoin('join_b', 'join_a.id', '=', 'join_b.a_id')->get()],
    'cross' => [fn () => joinTable()->crossJoin('join_b')->get()],
]);

it('takes more conditions in the join', function () {
    $query = joinTable()->join('join_b', fn ($join) => $join->on('join_a.id', '=', 'join_b.a_id')->where('join_b.note', 'y'));

    expect($query->pluck('join_a.id')->all())->toBe([2]);
});

it('searches the left table in a join', function () {
    expect(joined()->match('second')->pluck('join_b.note')->sort()->values()->all())->toBe(['y', 'z']);
});

it('returns clean rows from cursor()', function () {
    $row = joined()->select('join_a.id', 'join_b.note')->where('join_b.note', 'x')->cursor()->first();

    expect((array) $row)->toEqual(['id' => 1, 'note' => 'x']);
});

it('drops an alias that repeats the column, which would return no rows', function () {
    $row = joined()->select('join_a.id as id', 'join_b.note as note')->where('join_b.note', 'x')->first();

    expect((array) $row)->toEqual(['id' => 1, 'note' => 'x']);
});

it('reads a qualified column of a single table', function () {
    expect(joinTable()->select('join_a.*')->where('join_a.id', 1)->first()->name)->toBe('one')
        ->and(joinTable()->select('join_a.name')->orderBy('join_a.id', 'desc')->first()->name)->toBe('three')
        ->and(joinTable()->selectRaw('level, count(*) as total')->groupBy('join_a.level')->orderBy('level')->pluck('total')->all())->toEqual([2, 1]);
});

it('runs a sub-select with an order and a limit outside', function () {
    $inner = joinTable()->select('id', 'level')->orderBy('level', 'desc')->orderBy('id', 'desc')->limit(2);

    $outer = fn () => joinTable()->fromSub($inner, 'best')->orderBy('id', 'desc');

    expect($outer()->get()->pluck('id')->all())->toEqual([3, 1])
        ->and($outer()->limit(1)->get()->pluck('id')->all())->toEqual([3]);
});

it('keeps the bindings of a sub-select', function () {
    $inner = joinTable()->select('id', 'level')->where('level', '5')->orderBy('id')->limit(5);

    expect(joinTable()->fromSub($inner, 't')->orderBy('id', 'desc')->get()->pluck('id')->all())->toEqual([3, 2]);
});

it('pages a grouped query', function () {
    $page = joinTable()->selectRaw('level, count(*) as total')->groupBy('level')->orderBy('level')->paginate(1);

    expect($page->total())->toBe(2)
        ->and($page->items())->toHaveCount(1);

    $having = joinTable()->selectRaw('level, count(*) as total')->groupBy('level')->having('total', '>', 1)->orderBy('level')->paginate(5);

    expect($having->total())->toBe(1);
});

it('runs the examples of the compatibility guide', function () {
    $model = new class extends ManticoreModel
    {
        protected $table = 'join_a';

        protected $guarded = [];

        public $timestamps = false;
    };

    $joined = $model->newQuery()->join('join_b', 'join_a.id', '=', 'join_b.a_id')
        ->select('join_a.title', 'join_b.note')->where('join_b.note', 'x')->first();

    expect($joined->title)->toBe('first')
        ->and($joined->note)->toBe('x')
        ->and($model->newQuery()->selectRaw("*, REGEX(name, '^tw') as hit")->where('hit', 1)->pluck('id')->all())->toBe([2]);

    $years = joinTable('limits_dates');
    Schema::connection('manticore')->dropIfExists('limits_dates');
    Schema::connection('manticore')->create('limits_dates', function (Blueprint $table) {
        $table->timestamp('seen_at');
        $table->json('meta');
    });

    try {
        $years->insert(['id' => 1, 'seen_at' => '2026-03-01 12:00:00', 'meta' => '{"tags":[7,8]}']);

        expect(joinTable('limits_dates')->selectRaw('*, YEAR(seen_at) as year')->where('year', 2026)->get())->toHaveCount(1)
            ->and(fn () => joinTable('limits_dates')->selectRaw('*, YEAR(seen_at) as year')->where('year', 2026)->count())
            ->toThrow(QueryException::class)
            ->and(joinTable('limits_dates')->selectRaw('*, ANY(x = 7 FOR x IN meta.tags) as hit')->where('hit', 1)->get())->toHaveCount(1)
            ->and(joinTable('limits_dates')->selectRaw('*, ANY(x = 9 FOR x IN meta.tags) as hit')->where('hit', 1)->get())->toHaveCount(0);
    } finally {
        Schema::connection('manticore')->dropIfExists('limits_dates');
    }
});
