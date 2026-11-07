<?php

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ManticoreEloquent\Eloquent\ManticoreModel;

/**
 * Manticore returns 20 rows and stops at 1000 matches unless told otherwise, without an error.
 * Eloquent promises every row. Each test reads or writes past both numbers, on a table of 1500
 * documents that all belong to document 1.
 */
class RowLimitDocument extends ManticoreModel
{
    protected $table = 'row_limit_docs';

    protected $guarded = [];

    public $timestamps = false;

    public function siblings()
    {
        return $this->hasMany(self::class, 'owner_id');
    }

    public function linked()
    {
        return $this->belongsToMany(self::class, 'row_limit_links', 'doc_id', 'linked_id');
    }
}

function rowLimitTable(): Builder
{
    return DB::connection('manticore')->table('row_limit_docs');
}

/**
 * Insert rows 500 at a time, which keeps each statement small.
 */
function rowLimitInsert(string $table, array $rows): void
{
    foreach (array_chunk($rows, 500) as $chunk) {
        DB::connection('manticore')->table($table)->insert($chunk);
    }
}

/**
 * Two owners of document 1, so a LEFT JOIN gives each document two rows.
 */
function rowLimitOwners(): void
{
    $schema = Schema::connection('manticore');
    $schema->dropIfExists('row_limit_owners');
    $schema->create('row_limit_owners', function (Blueprint $table) {
        $table->integer('doc_id');
    });
    DB::connection('manticore')->table('row_limit_owners')->insert([['id' => 1, 'doc_id' => 1], ['id' => 2, 'doc_id' => 1]]);
}

/**
 * A LEFT JOIN of the first 700 documents: 1400 rows, past the 1000 of max_matches. It stays within
 * the 1500 documents of the left table, which a join returns no more rows than, as ServerLimitsTest
 * shows.
 */
function rowLimitJoin(): Builder
{
    return rowLimitTable()
        ->leftJoin('row_limit_owners', 'row_limit_docs.owner_id', '=', 'row_limit_owners.doc_id')
        ->where('row_limit_docs.id', '<=', 700);
}

/**
 * Run a test with the default_limit of the connection set, and put it back afterwards.
 */
function withDefaultLimit(mixed $limit, Closure $test): void
{
    config(['database.connections.manticore.default_limit' => $limit]);
    DB::purge('manticore');

    try {
        $test();
    } finally {
        config(['database.connections.manticore.default_limit' => null]);
        DB::purge('manticore');
    }
}

beforeEach(function () {
    try {
        DB::connection('manticore')->getPdo();
    } catch (Throwable $e) {
        $this->markTestSkipped('Manticore not reachable on the MySQL port: '.$e->getMessage());
    }

    $schema = Schema::connection('manticore');
    $schema->dropIfExists('row_limit_docs');
    $schema->create('row_limit_docs', function (Blueprint $table) {
        $table->integer('owner_id');
    });

    rowLimitInsert('row_limit_docs', array_map(fn ($id) => ['id' => $id, 'owner_id' => 1], range(1, 1500)));
});

afterEach(function () {
    try {
        foreach (['row_limit_docs', 'row_limit_owners', 'row_limit_links'] as $table) {
            Schema::connection('manticore')->dropIfExists($table);
        }
    } catch (Throwable $e) {
        // Nothing to clean up when the server is not reachable.
    }
});

// Reading without a limit

it('returns every row without a limit', function () {
    expect(RowLimitDocument::all())->toHaveCount(1500)
        ->and(rowLimitTable()->get())->toHaveCount(1500)
        ->and(rowLimitTable()->pluck('id'))->toHaveCount(1500);
});

it('returns every row in order, so the last ones are not the ones cut', function () {
    expect(rowLimitTable()->orderByDesc('id')->pluck('id')->all())->toBe(range(1500, 1));
});

it('streams every row through cursor()', function () {
    expect(iterator_count(rowLimitTable()->cursor()))->toBe(1500);
});

it('finds every id asked for', function () {
    expect(RowLimitDocument::findMany(range(1, 1500)))->toHaveCount(1500)
        ->and(rowLimitTable()->whereIn('id', range(1, 1500))->count())->toBe(1500)
        ->and(rowLimitTable()->whereIn('id', range(1, 1500))->get())->toHaveCount(1500);
});

it('returns every group of a grouped query', function () {
    expect(rowLimitTable()->groupBy('id')->get())->toHaveCount(1500)
        ->and(rowLimitTable()->select('id')->distinct()->get())->toHaveCount(1500);
});

it('counts and sums every row', function () {
    expect(rowLimitTable()->count())->toBe(1500)
        ->and((int) rowLimitTable()->sum('owner_id'))->toBe(1500)
        ->and((int) rowLimitTable()->max('id'))->toBe(1500);
});

it('counts every row of a LEFT JOIN', function () {
    rowLimitOwners();

    expect(rowLimitJoin()->count())->toBe(1400)
        ->and(rowLimitJoin()->get())->toHaveCount(1400);
});

it('limit: a join returns no more rows than the documents of its left table, and counts no more', function () {
    rowLimitOwners();
    $join = fn () => rowLimitTable()->leftJoin('row_limit_owners', 'row_limit_docs.owner_id', '=', 'row_limit_owners.doc_id');

    expect($join()->get())->toHaveCount(1500)
        ->and($join()->count())->toBe(1500);
});

it('keeps an explicit limit', function () {
    expect(rowLimitTable()->limit(5)->get())->toHaveCount(5);
});

it('applies an offset without a limit', function () {
    expect(rowLimitTable()->offset(1490)->get())->toHaveCount(10);
});

it('lets an explicit max_matches cap the rows', function () {
    expect(rowLimitTable()->maxMatches(100)->get())->toHaveCount(100);
});

// Relations

it('loads every related row, lazily and eagerly', function () {
    $owner = RowLimitDocument::find(1);

    expect($owner->siblings)->toHaveCount(1500)
        ->and(RowLimitDocument::with('siblings')->whereKey(1)->first()->siblings)->toHaveCount(1500);
});

it('eager loads a relation for every parent', function () {
    $parents = RowLimitDocument::with('siblings')->get();

    expect($parents)->toHaveCount(1500)
        ->and($parents->firstWhere('id', 1)->siblings)->toHaveCount(1500)
        ->and($parents->firstWhere('id', 2)->siblings)->toHaveCount(0);
});

it('loads every row through a pivot table', function () {
    $schema = Schema::connection('manticore');
    $schema->dropIfExists('row_limit_links');
    $schema->create('row_limit_links', function (Blueprint $table) {
        $table->integer('doc_id');
        $table->integer('linked_id');
    });
    rowLimitInsert('row_limit_links', array_map(fn ($id) => ['id' => $id, 'doc_id' => 1, 'linked_id' => $id], range(1, 1500)));

    expect(RowLimitDocument::find(1)->linked)->toHaveCount(1500)
        ->and(RowLimitDocument::with('linked')->whereKey(1)->first()->linked)->toHaveCount(1500);
});

// Paging past the 1000 matches Manticore stops at

it('paginates past the 1000 rows Manticore stops at by default', function () {
    $page = RowLimitDocument::query()->orderBy('id')->paginate(perPage: 10, page: 145);

    expect($page->total())->toBe(1500)
        ->and($page->lastPage())->toBe(150)
        ->and($page->getCollection()->modelKeys())->toBe(range(1441, 1450));
});

it('paginates a grouped query past 1000 rows', function () {
    $page = RowLimitDocument::query()->groupBy('id')->orderBy('id')->paginate(perPage: 10, page: 145);

    expect($page->total())->toBe(1500)
        ->and($page->getCollection()->modelKeys())->toBe(range(1441, 1450));
});

it('paginates a LEFT JOIN with the total of its rows', function () {
    rowLimitOwners();

    expect(rowLimitJoin()->paginate(10)->total())->toBe(1400);
});

it('simple paginates past 1000 rows', function () {
    $page = RowLimitDocument::query()->orderBy('id')->simplePaginate(perPage: 10, page: 145);

    expect($page->getCollection()->modelKeys())->toBe(range(1441, 1450))
        ->and($page->hasMorePages())->toBeTrue();
});

it('cursor paginates through every row', function () {
    $ids = [];
    $cursor = null;

    do {
        $page = RowLimitDocument::query()->orderBy('id')->cursorPaginate(perPage: 400, cursor: $cursor);
        $ids = array_merge($ids, $page->getCollection()->modelKeys());
        $cursor = $page->nextCursor();
    } while ($cursor !== null);

    expect($ids)->toBe(range(1, 1500));
});

it('pages a query builder past 1000 rows with offset() and limit()', function () {
    expect(rowLimitTable()->orderBy('id')->offset(1200)->limit(5)->pluck('id')->all())->toBe(range(1201, 1205));
});

it('caps the total of paginate() at a max_matches set by hand', function () {
    $query = fn () => RowLimitDocument::query()->orderBy('id')->maxMatches(100);

    expect($query()->paginate(perPage: 15)->total())->toBe(100)
        ->and($query()->paginate(perPage: 15)->lastPage())->toBe(7)
        ->and($query()->paginate(perPage: 15, page: 7)->getCollection()->modelKeys())->toBe(range(91, 100));
});

// Walking a table in chunks

it('walks every row with chunk(), which pages by offset', function () {
    $ids = [];

    RowLimitDocument::query()->orderBy('id')->chunk(400, function ($rows) use (&$ids) {
        $ids = array_merge($ids, $rows->modelKeys());
    });

    expect($ids)->toBe(range(1, 1500));
});

it('walks every row with chunkById()', function () {
    $ids = [];

    RowLimitDocument::query()->chunkById(400, function ($rows) use (&$ids) {
        $ids = array_merge($ids, $rows->modelKeys());
    });

    expect($ids)->toBe(range(1, 1500));
});

it('walks every row with each(), lazy() and lazyById()', function () {
    $each = 0;
    RowLimitDocument::query()->orderBy('id')->each(function () use (&$each) {
        $each++;
    }, 400);

    expect($each)->toBe(1500)
        ->and(RowLimitDocument::query()->orderBy('id')->lazy(400)->pluck('id')->all())->toBe(range(1, 1500))
        ->and(RowLimitDocument::query()->lazyById(400)->pluck('id')->all())->toBe(range(1, 1500));
});

// Writing every row

it('updates every row that matches', function () {
    expect(rowLimitTable()->where('owner_id', 1)->update(['owner_id' => 2]))->toBe(1500)
        ->and(rowLimitTable()->where('owner_id', 2)->count())->toBe(1500);
});

it('updates every row of a query without a where', function () {
    expect(RowLimitDocument::query()->update(['owner_id' => 3]))->toBe(1500)
        ->and(rowLimitTable()->where('owner_id', 3)->count())->toBe(1500);
});

it('deletes every row that matches', function () {
    expect(rowLimitTable()->where('owner_id', 1)->delete())->toBe(1500)
        ->and(rowLimitTable()->count())->toBe(0);
});

// Facets

it('returns every row with the facets when there is no limit', function () {
    $result = rowLimitTable()->facet('owner_id')->getWithFacets();

    expect($result['rows'])->toHaveCount(1500)
        ->and($result['facets']['owner_id']->pluck('count(*)', 'owner_id')->all())->toEqual([1 => 1500]);
});

it('counts the facets over every match, past the limit on the rows', function () {
    $result = rowLimitTable()->limit(20)->facet('owner_id')->getWithFacets();

    expect($result['rows'])->toHaveCount(20)
        ->and($result['facets']['owner_id']->pluck('count(*)', 'owner_id')->all())->toEqual([1 => 1500]);
});

it('returns 20 groups of a facet unless the facet sets a limit', function () {
    $result = rowLimitTable()->limit(1)
        ->facet('id')
        ->facet(DB::raw('id AS every ORDER BY id ASC LIMIT 30'))
        ->getWithFacets();

    expect($result['facets']['id'])->toHaveCount(20)
        ->and($result['facets']['every']->pluck('every')->all())->toEqual(range(1, 30));
});

it('returns every group a facet limit asks for, past 1000', function () {
    $result = rowLimitTable()->limit(1)->facet(DB::raw('id ORDER BY id ASC LIMIT 1500'))->getWithFacets();

    expect($result['facets']['id'])->toHaveCount(1500);
});

// default_limit

it('caps a query without a limit at default_limit', function () {
    withDefaultLimit(100, function () {
        expect(rowLimitTable()->get())->toHaveCount(100)
            ->and(RowLimitDocument::all())->toHaveCount(100)
            ->and(rowLimitTable()->pluck('id'))->toHaveCount(100)
            ->and(iterator_count(rowLimitTable()->cursor()))->toBe(100);
    });
});

it('cuts a relation larger than default_limit short, as the docs warn', function () {
    withDefaultLimit(100, function () {
        expect(RowLimitDocument::find(1)->siblings)->toHaveCount(100);
    });
});

it('raises max_matches for a default_limit past 1000', function () {
    withDefaultLimit(1200, function () {
        expect(rowLimitTable()->get())->toHaveCount(1200);
    });
});

it('keeps an explicit limit over default_limit', function () {
    withDefaultLimit(100, function () {
        expect(rowLimitTable()->limit(500)->get())->toHaveCount(500);
    });
});

it('counts every row with default_limit set', function () {
    rowLimitOwners();

    withDefaultLimit(100, function () {
        expect(rowLimitTable()->count())->toBe(1500)
            ->and(rowLimitJoin()->count())->toBe(1400)
            ->and(RowLimitDocument::query()->paginate(10)->total())->toBe(1500)
            ->and(RowLimitDocument::query()->groupBy('id')->paginate(10)->total())->toBe(1500)
            ->and(rowLimitJoin()->paginate(10)->total())->toBe(1400);
    });
});

it('pages and walks every row with default_limit set', function () {
    withDefaultLimit(100, function () {
        $ids = [];
        RowLimitDocument::query()->orderBy('id')->chunk(400, function ($rows) use (&$ids) {
            $ids = array_merge($ids, $rows->modelKeys());
        });

        expect($ids)->toBe(range(1, 1500))
            ->and(RowLimitDocument::query()->lazyById(400)->count())->toBe(1500)
            ->and(RowLimitDocument::query()->orderBy('id')->paginate(perPage: 10, page: 145)->getCollection()->modelKeys())->toBe(range(1441, 1450));
    });
});

it('writes every row with default_limit set', function () {
    withDefaultLimit(100, function () {
        expect(rowLimitTable()->where('owner_id', 1)->update(['owner_id' => 2]))->toBe(1500);
    });
});
