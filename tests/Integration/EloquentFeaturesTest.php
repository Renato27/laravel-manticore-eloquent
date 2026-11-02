<?php

use Illuminate\Database\Eloquent\SoftDeletes;
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

class FeatureSoftPost extends ManticoreModel
{
    use SoftDeletes;

    protected $table = 'feature_soft';
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
    $schema->dropIfExists('feature_soft');
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
    foreach (['feature_posts', 'feature_soft'] as $table) {
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

it('limit: SoftDeletes fails with an error when querying', function () {
    Schema::connection('manticore')->create('feature_soft', function (Blueprint $table) {
        $table->text('title');
        $table->timestamp('deleted_at');
    });
    DB::connection('manticore')->table('feature_soft')->insert(['id' => 1, 'title' => 'a']);

    expect(fn () => FeatureSoftPost::query()->count())->toThrow(QueryException::class);
});
