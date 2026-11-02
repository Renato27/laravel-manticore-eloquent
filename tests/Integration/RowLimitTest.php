<?php

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ManticoreEloquent\Eloquent\ManticoreModel;

/**
 * Manticore returns 20 rows unless told otherwise. Eloquent promises every row.
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
}

function rowLimitTable(): Builder
{
    return DB::connection('manticore')->table('row_limit_docs');
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

    $rows = [];

    for ($id = 1; $id <= 1500; $id++) {
        $rows[] = ['id' => $id, 'owner_id' => 1];
    }

    foreach (array_chunk($rows, 500) as $chunk) {
        rowLimitTable()->insert($chunk);
    }
});

afterEach(function () {
    try {
        Schema::connection('manticore')->dropIfExists('row_limit_docs');
    } catch (Throwable $e) {
        // Nothing to clean up when the server is not reachable.
    }
});

it('returns every row without a limit', function () {
    expect(RowLimitDocument::all())->toHaveCount(1500)
        ->and(rowLimitTable()->get())->toHaveCount(1500)
        ->and(rowLimitTable()->pluck('id'))->toHaveCount(1500);
});

it('streams every row through cursor()', function () {
    expect(iterator_count(rowLimitTable()->cursor()))->toBe(1500);
});

it('loads every related row, lazily and eagerly', function () {
    $owner = RowLimitDocument::find(1);

    expect($owner->siblings)->toHaveCount(1500)
        ->and(RowLimitDocument::with('siblings')->whereKey(1)->first()->siblings)->toHaveCount(1500);
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
