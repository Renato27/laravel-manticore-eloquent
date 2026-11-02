<?php

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

function featureTable(): \Illuminate\Database\Query\Builder
{
    return DB::connection('manticore')->table('feature_posts');
}

beforeEach(function () {
    try {
        DB::connection('manticore')->getPdo();
    } catch (\Throwable $e) {
        $this->markTestSkipped('Manticore not reachable on the MySQL port: ' . $e->getMessage());
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
        } catch (\Throwable) {
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

it('limit: increment() and decrement() fail with an error', function () {
    expect(fn () => FeaturePost::where('id', 1)->increment('views'))->toThrow(QueryException::class);
    expect(fn () => FeaturePost::where('id', 1)->decrement('views'))->toThrow(QueryException::class);
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
