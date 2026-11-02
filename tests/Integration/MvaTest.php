<?php

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ManticoreEloquent\Eloquent\Casts\MvaCast;
use ManticoreEloquent\Eloquent\ManticoreModel;

class MvaDocument extends ManticoreModel
{
    protected $table = 'mva_docs';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = ['tag_ids' => MvaCast::class];
}

function mvaTable(): Builder
{
    return DB::connection('manticore')->table('mva_docs');
}

beforeEach(function () {
    try {
        DB::connection('manticore')->getPdo();
    } catch (Throwable $e) {
        $this->markTestSkipped('Manticore not reachable on the MySQL port: '.$e->getMessage());
    }

    Schema::connection('manticore')->dropIfExists('mva_docs');
    DB::connection('manticore')->statement('CREATE TABLE mva_docs (title string, tag_ids multi, big_ids multi64)');
});

afterEach(function () {
    try {
        Schema::connection('manticore')->dropIfExists('mva_docs');
    } catch (Throwable $e) {
        // Nothing to clean up when the server is not reachable.
    }
});

it('round-trips a list through a model', function () {
    MvaDocument::create(['id' => 1, 'title' => 'a', 'tag_ids' => [2, 1]]);

    expect(MvaDocument::find(1)->tag_ids)->toBe([1, 2]);
});

it('updates a list through a model', function () {
    MvaDocument::create(['id' => 1, 'title' => 'a', 'tag_ids' => [1]]);

    $doc = MvaDocument::find(1);
    $doc->tag_ids = [7, 8];
    $doc->save();

    expect(MvaDocument::find(1)->tag_ids)->toBe([7, 8]);

    $doc->update(['tag_ids' => []]);

    expect(MvaDocument::find(1)->tag_ids)->toBe([]);
});

it('filters on one value of the list', function () {
    MvaDocument::create(['id' => 1, 'title' => 'a', 'tag_ids' => [1, 2]]);
    MvaDocument::create(['id' => 2, 'title' => 'b', 'tag_ids' => [3]]);

    expect(MvaDocument::where('tag_ids', 2)->pluck('id')->all())->toBe([1])
        ->and(MvaDocument::whereIn('tag_ids', [2, 3])->count())->toBe(2);
});

it('takes a list from the Query Builder, without a cast', function () {
    mvaTable()->insert(['id' => 1, 'title' => 'a', 'tag_ids' => [1, 2], 'big_ids' => [5000000000]]);
    mvaTable()->insert([['id' => 2, 'title' => 'b', 'tag_ids' => [3], 'big_ids' => []]]);
    mvaTable()->where('id', 2)->update(['tag_ids' => [4, 5]]);
    mvaTable()->replace(['id' => 3, 'title' => 'c', 'tag_ids' => [6]]);

    expect(mvaTable()->where('id', 1)->value('tag_ids'))->toBe('1,2')
        ->and(mvaTable()->where('id', 1)->value('big_ids'))->toBe('5000000000')
        ->and(mvaTable()->where('id', 2)->value('tag_ids'))->toBe('4,5')
        ->and(mvaTable()->where('id', 3)->value('tag_ids'))->toBe('6');
});

it('replicates a document', function () {
    MvaDocument::create(['id' => 1, 'title' => 'a', 'tag_ids' => [1, 2]]);

    $copy = MvaDocument::find(1)->replicate();
    $copy->id = 2;
    $copy->save();

    expect(MvaDocument::find(2)->tag_ids)->toBe([1, 2]);
});
