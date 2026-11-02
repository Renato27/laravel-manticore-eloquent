<?php

use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ManticoreEloquent\Eloquent\ManticoreModel;

/**
 * The parts of Eloquent and the Query Builder the package inherits, run against a real
 * Manticore, plus the ones that do not work. Those are listed in docs/known-limits.md.
 */
class FeaturePost extends ManticoreModel
{
    protected $table = 'feature_posts';

    protected $guarded = [];

    public $timestamps = false;
}

function featureTable(): Builder
{
    return DB::connection('manticore')->table('feature_posts');
}

beforeEach(function () {
    try {
        DB::connection('manticore')->getPdo();
    } catch (Throwable $e) {
        $this->markTestSkipped('Manticore not reachable on the MySQL port: '.$e->getMessage());
    }

    $schema = Schema::connection('manticore');
    $schema->dropIfExists('feature_posts');
    $schema->create('feature_posts', function (Blueprint $table) {
        $table->text('title');
        $table->integer('views');
        $table->string('slug');
    });

    FeaturePost::create(['id' => 1, 'title' => 'first', 'views' => 10, 'slug' => 'a']);
    FeaturePost::create(['id' => 2, 'title' => 'second', 'views' => 20, 'slug' => 'b']);
    FeaturePost::create(['id' => 3, 'title' => 'third', 'views' => 30, 'slug' => 'b']);
});

afterEach(function () {
    foreach (['feature_posts'] as $table) {
        try {
            Schema::connection('manticore')->dropIfExists($table);
        } catch (Throwable) {
        }
    }
});

it('finds or creates a document', function () {
    expect(FeaturePost::firstOrCreate(['slug' => 'a'], ['id' => 9, 'title' => 'x', 'views' => 0])->id)->toBe(1);
    expect(FeaturePost::firstOrCreate(['slug' => 'new'], ['id' => 9, 'title' => 'x', 'views' => 0])->id)->toBe(9);
});

it('updates or creates a document', function () {
    FeaturePost::updateOrCreate(['slug' => 'a'], ['views' => 50]);

    expect(FeaturePost::find(1)->views)->toBe(50);
});

it('pages with simplePaginate() and cursorPaginate()', function () {
    expect(collect(FeaturePost::orderBy('id')->simplePaginate(2)->items())->pluck('id')->all())->toBe([1, 2]);

    $first = FeaturePost::orderBy('id')->cursorPaginate(2);
    expect(collect($first->items())->pluck('id')->all())->toBe([1, 2]);

    $next = FeaturePost::orderBy('id')->cursorPaginate(2, ['*'], 'cursor', $first->nextCursor());
    expect(collect($next->items())->pluck('id')->all())->toBe([3]);
});

it('filters with between, not in and or', function () {
    expect(FeaturePost::whereBetween('views', [15, 25])->pluck('id')->all())->toBe([2]);
    expect(FeaturePost::whereNotIn('id', [1])->orderBy('id')->pluck('id')->all())->toBe([2, 3]);
    expect(FeaturePost::where('id', 1)->orWhere('id', 3)->orderBy('id')->pluck('id')->all())->toBe([1, 3]);
});

it('groups rows', function () {
    $groups = featureTable()->selectRaw('slug, count(*) as total')->groupBy('slug')->orderBy('slug')->pluck('total', 'slug')->all();

    expect($groups)->toBe(['a' => 1, 'b' => 2]);
});

it('truncates a table', function () {
    featureTable()->truncate();

    expect(FeaturePost::count())->toBe(0);
});

it('refuses increment() and decrement(), which could not be atomic', function () {
    expect(fn () => FeaturePost::where('id', 1)->increment('views'))->toThrow(LogicException::class, 'increment');
    expect(fn () => FeaturePost::find(1)->decrement('views'))->toThrow(LogicException::class, 'decrement');
    expect(fn () => featureTable()->incrementEach(['views' => 1]))->toThrow(LogicException::class, 'increment');
    expect(fn () => featureTable()->decrementEach(['views' => 1]))->toThrow(LogicException::class, 'decrement');
});

it('deletes every document without a filter', function () {
    expect(FeaturePost::query()->delete())->toBe(3)
        ->and(FeaturePost::count())->toBe(0);
});

it('updates every document without a filter', function () {
    expect(FeaturePost::query()->update(['views' => 7]))->toBe(3)
        ->and(FeaturePost::where('views', 7)->count())->toBe(3);
});

it('deletes and updates every document of a uuid table', function () {
    Schema::connection('manticore')->dropIfExists('feature_uuid');
    Schema::connection('manticore')->create('feature_uuid', function (Blueprint $table) {
        $table->uuid('id');
        $table->integer('views');
    });

    try {
        $table = fn () => DB::connection('manticore')->table('feature_uuid');
        $table()->insert([['views' => 1], ['views' => 2]]);

        expect($table()->update(['views' => 7]))->toBe(2)
            ->and($table()->where('views', 7)->count())->toBe(2)
            ->and($table()->delete())->toBe(2)
            ->and($table()->count())->toBe(0);
    } finally {
        Schema::connection('manticore')->dropIfExists('feature_uuid');
    }
});

it('keeps a filter and deletes by id as usual', function () {
    expect(FeaturePost::where('id', 1)->delete())->toBe(1)
        ->and(featureTable()->delete(2))->toBe(1)
        ->and(FeaturePost::count())->toBe(1);
});

it('fails loudly on a limit in update() and delete(), which the server does not have', function () {
    expect(fn () => featureTable()->limit(1)->update(['views' => 1]))->toThrow(QueryException::class)
        ->and(fn () => featureTable()->limit(1)->delete())->toThrow(QueryException::class)
        ->and(FeaturePost::count())->toBe(3);
});

it('runs updateOrInsert() both ways', function () {
    expect(featureTable()->updateOrInsert(['id' => 1], ['views' => 11]))->toBeTrue()
        ->and(FeaturePost::find(1)->views)->toBe(11)
        ->and(featureTable()->updateOrInsert(['id' => 9], ['title' => 'new', 'views' => 12, 'slug' => 'n']))->toBeTrue()
        ->and(FeaturePost::find(9)->views)->toBe(12);
});

it('updates every match in updateOrInsert(), which cannot stop at one', function () {
    featureTable()->updateOrInsert(['slug' => 'b'], ['views' => 50]);

    expect(FeaturePost::where('views', 50)->pluck('id')->sort()->values()->all())->toBe([2, 3]);
});

it('compiles distinct() to a group by', function () {
    expect(featureTable()->distinct()->orderBy('slug')->pluck('slug')->all())->toBe(['a', 'b'])
        ->and(featureTable()->select('slug', 'views')->distinct()->count('slug'))->toBe(2)
        ->and(featureTable()->select('slug')->distinct()->orderBy('slug')->get()->pluck('slug')->all())->toBe(['a', 'b']);
});

it('runs upsert() as a REPLACE when every column is written', function () {
    $rows = [
        ['id' => 1, 'title' => 'changed', 'views' => 100, 'slug' => 'z'],
        ['id' => 9, 'title' => 'new', 'views' => 900, 'slug' => 'y'],
    ];

    expect(featureTable()->upsert($rows, 'id'))->toBe(2)
        ->and(FeaturePost::find(1)->views)->toBe(100)
        ->and(FeaturePost::find(9)->views)->toBe(900)
        ->and(FeaturePost::count())->toBe(4);

    expect(FeaturePost::upsert(['id' => 2, 'title' => 'model', 'views' => 200, 'slug' => 'w'], ['id'], ['title', 'views', 'slug']))->toBe(1)
        ->and(FeaturePost::find(2)->views)->toBe(200);
});

it('refuses an upsert() a REPLACE could not honor', function (array $unique, ?array $update) {
    featureTable()->upsert(['id' => 1, 'title' => 'x', 'views' => 2, 'slug' => 'c'], $unique, $update);
})->with([
    'another unique key' => [['slug'], null],
    'a partial update' => [['id'], ['views']],
])->throws(LogicException::class, 'upsert()');

it('does nothing on an empty upsert()', function () {
    expect(featureTable()->upsert([], 'id'))->toBe(0);
});

it('fails loudly on insertOrIgnore(), which the server does not have', function () {
    featureTable()->insertOrIgnore(['id' => 1, 'title' => 'x', 'views' => 2, 'slug' => 'c']);
})->throws(QueryException::class);

it('rolls back and commits writes to one table in a transaction', function () {
    DB::connection('manticore')->transaction(function () {
        FeaturePost::create(['id' => 4, 'title' => 'kept', 'views' => 1, 'slug' => 'k']);
        FeaturePost::where('id', 1)->delete();
    });

    expect(FeaturePost::find(4))->not->toBeNull()
        ->and(FeaturePost::find(1))->toBeNull();

    expect(fn () => DB::connection('manticore')->transaction(function () {
        FeaturePost::create(['id' => 5, 'title' => 'gone', 'views' => 1, 'slug' => 'g']);
        FeaturePost::where('id', 2)->delete();

        throw new RuntimeException('rollback');
    }))->toThrow(RuntimeException::class, 'rollback');

    expect(FeaturePost::find(5))->toBeNull()
        ->and(FeaturePost::find(2))->not->toBeNull();
});

it('limit: update() does not roll back, and a transaction cannot read its own writes', function () {
    $connection = DB::connection('manticore');

    $connection->beginTransaction();
    FeaturePost::where('id', 1)->update(['views' => 99]);
    FeaturePost::create(['id' => 4, 'title' => 'unseen', 'views' => 1, 'slug' => 'u']);
    $unseen = FeaturePost::find(4);
    $connection->rollBack();

    expect($unseen)->toBeNull()
        ->and(FeaturePost::find(1)->views)->toBe(99);
});

it('limit: a transaction can only write to one table', function () {
    Schema::connection('manticore')->dropIfExists('feature_other');
    Schema::connection('manticore')->create('feature_other', fn (Blueprint $table) => $table->integer('n'));

    try {
        expect(fn () => DB::connection('manticore')->transaction(function () {
            featureTable()->where('id', 1)->delete();
            DB::connection('manticore')->table('feature_other')->insert(['id' => 1, 'n' => 1]);
        }))->toThrow(QueryException::class, 'another table');

        expect(FeaturePost::find(1))->not->toBeNull();
    } finally {
        Schema::connection('manticore')->dropIfExists('feature_other');
    }
});

it('limit: insertUsing() fails with the error of the server', function () {
    featureTable()->insertUsing(['id', 'slug'], featureTable()->select('id', 'slug'));
})->throws(QueryException::class);

it('limit: SQL the server does not have fails with its error', function (Closure $query) {
    expect(fn () => $query(featureTable())->get())->toThrow(QueryException::class);
})->with([
    'union' => [fn ($q) => $q->union(featureTable())],
    'like' => [fn ($q) => $q->where('slug', 'like', 'a%')],
    'whereColumn' => [fn ($q) => $q->whereColumn('views', 'id')],
    'whereDate' => [fn ($q) => $q->whereDate('views', '2026-01-01')],
    'whereExists' => [fn ($q) => $q->whereExists(fn ($sub) => $sub->from('feature_posts'))],
    'a subquery' => [fn ($q) => $q->whereIn('id', fn ($sub) => $sub->select('id')->from('feature_posts'))],
    'selectSub' => [fn ($q) => $q->selectSub(featureTable()->selectRaw('count(*)'), 'c')],
    'joinSub' => [fn ($q) => $q->joinSub(featureTable(), 'o', fn ($join) => $join->on('feature_posts.id', '=', 'o.id'))],
    'whereLike' => [fn ($q) => method_exists($q, 'whereLike') ? $q->whereLike('slug', 'a%') : $q->where('slug', 'like', 'a%')],
    'whereBetweenColumns' => [fn ($q) => $q->whereBetweenColumns('views', ['id', 'views'])],
    'whereValueBetween' => [fn ($q) => method_exists($q, 'whereValueBetween') ? $q->whereValueBetween(1, ['id', 'views']) : $q->whereColumn('id', 'views')],
    'whereYear' => [fn ($q) => $q->whereYear('views', 2026)],
    'whereMonth' => [fn ($q) => $q->whereMonth('views', 1)],
    'whereDay' => [fn ($q) => $q->whereDay('views', 1)],
    'whereTime' => [fn ($q) => $q->whereTime('views', '=', '10:00:00')],
    'whereJsonContainsKey' => [fn ($q) => $q->whereJsonContainsKey('slug->a')],
]);
