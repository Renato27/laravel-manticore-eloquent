<?php

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ManticoreEloquent\Eloquent\ManticoreModel;

class FacetDocument extends ManticoreModel
{
    protected $table = 'facet_docs';

    protected $guarded = [];

    public $timestamps = false;
}

function facetTable(): Builder
{
    return DB::connection('manticore')->table('facet_docs');
}

beforeEach(function () {
    try {
        DB::connection('manticore')->getPdo();
    } catch (Throwable $e) {
        $this->markTestSkipped('Manticore not reachable on the MySQL port: '.$e->getMessage());
    }

    Schema::connection('manticore')->dropIfExists('facet_docs');
    Schema::connection('manticore')->create('facet_docs', function (Blueprint $table) {
        $table->text('title');
        $table->string('category');
        $table->integer('year');
    });

    facetTable()->insert([
        ['id' => 1, 'title' => 'alpha', 'category' => 'a', 'year' => 2020],
        ['id' => 2, 'title' => 'alpha', 'category' => 'a', 'year' => 2021],
        ['id' => 3, 'title' => 'alpha', 'category' => 'b', 'year' => 2021],
        ['id' => 4, 'title' => 'beta', 'category' => 'c', 'year' => 2022],
    ]);
});

afterEach(function () {
    try {
        Schema::connection('manticore')->dropIfExists('facet_docs');
    } catch (Throwable $e) {
        // Nothing to clean up when the server is not reachable.
    }
});

it('returns the rows and each facet', function () {
    $result = facetTable()->match('alpha')->facet('category')->facet('year')->getWithFacets();

    expect($result['rows'])->toHaveCount(3)
        ->and($result['rows']->sortBy('id')->pluck('id')->all())->toBe([1, 2, 3])
        ->and(array_keys($result['facets']))->toBe(['category', 'year'])
        ->and($result['facets']['category']->pluck('count(*)', 'category')->sortKeys()->all())->toEqual(['a' => 2, 'b' => 1])
        ->and($result['facets']['year']->pluck('count(*)', 'year')->sortKeys()->all())->toEqual([2020 => 1, 2021 => 2]);
});

it('names a facet by its alias', function () {
    $result = facetTable()->facet('category', 'kind')->getWithFacets();

    expect(array_keys($result['facets']))->toBe(['kind'])
        ->and($result['facets']['kind']->first())->toHaveProperty('kind');
});

it('respects filters and a limit on the rows', function () {
    $result = facetTable()->where('year', '2021')->limit(1)->facet('category')->getWithFacets(['id']);

    expect($result['rows'])->toHaveCount(1)
        ->and($result['rows']->first())->toHaveProperty('id')
        ->and($result['facets']['category']->pluck('count(*)', 'category')->sortKeys()->all())->toEqual(['a' => 1, 'b' => 1]);
});

it('returns no facets when none were asked for', function () {
    expect(facetTable()->getWithFacets())->toMatchArray(['facets' => []])
        ->and(facetTable()->getWithFacets()['rows'])->toHaveCount(4);
});

it('works from a model', function () {
    $result = FacetDocument::query()->facet('category')->getWithFacets();

    expect($result['rows'])->toHaveCount(4)
        ->and($result['rows']->first())->toBeInstanceOf(FacetDocument::class)
        ->and($result['facets']['category'])->toHaveCount(3);
});

it('applies the global scopes of a model', function () {
    $model = new class extends FacetDocument
    {
        protected static function booted(): void
        {
            self::addGlobalScope('recent', fn ($query) => $query->where('year', 2022));
        }
    };

    expect($model->newQuery()->facet('category')->getWithFacets()['rows']->pluck('id')->all())->toBe([4]);
});

it('keeps get() returning only the rows', function () {
    expect(facetTable()->facet('category')->get())->toHaveCount(4);
});
