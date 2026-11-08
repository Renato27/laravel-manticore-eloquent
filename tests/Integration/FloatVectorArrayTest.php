<?php

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ManticoreEloquent\Eloquent\Casts\VectorArrayCast;
use ManticoreEloquent\Eloquent\ManticoreModel;

/**
 * Several vectors per document, as in https://manual.manticoresearch.com/Creating_a_table/Data_types#Float-vector-array.
 * `chunks` is KNN-indexed and `notes` is stored only, the two modes the manual describes.
 */
class ChunkedArticle extends ManticoreModel
{
    protected $table = 'fva_articles';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = ['chunks' => VectorArrayCast::class, 'notes' => VectorArrayCast::class];
}

function chunkedTable(): Builder
{
    return DB::connection('manticore')->table('fva_articles');
}

beforeEach(function () {
    try {
        DB::connection('manticore')->getPdo();
    } catch (Throwable $e) {
        $this->markTestSkipped('Manticore not reachable on the MySQL port: '.$e->getMessage());
    }

    if (! supportsFloatVectorArray()) {
        $this->markTestSkipped('float_vector_array needs Manticore 29.9.0 or later.');
    }

    Schema::connection('manticore')->dropIfExists('fva_articles');
    Schema::connection('manticore')->create('fva_articles', function (Blueprint $table) {
        $table->text('title');
        $table->floatVectorArray('chunks', dims: 2);
        $table->floatVectorArray('notes', knnType: null);
    });
});

afterEach(function () {
    try {
        Schema::connection('manticore')->dropIfExists('fva_articles');
    } catch (Throwable) {
        // Nothing to clean up when the server is not reachable.
    }
});

it('creates the columns a migration declares, and reports them', function () {
    $definition = DB::connection('manticore')->select('SHOW CREATE TABLE fva_articles')[0]->{'Create Table'};
    $schema = Schema::connection('manticore');

    expect($definition)->toContain("chunks float_vector_array knn_type='hnsw' knn_dims='2' hnsw_similarity='L2'")
        ->and($definition)->toMatch('/^notes float_vector_array$/m')
        ->and(array_column($schema->getColumns('fva_articles'), 'type', 'name'))->toMatchArray(['chunks' => 'float_vector_array', 'notes' => 'float_vector_array'])
        ->and(array_column($schema->getIndexes('fva_articles'), 'columns', 'name'))->toBe(['primary' => ['id'], 'chunks_knn' => ['chunks'], 'fulltext' => ['title']]);
});

it('writes and reads back several vectors per document through the cast', function () {
    ChunkedArticle::create(['id' => 1, 'title' => 'a', 'chunks' => [[1, 0], [0, 0.5]], 'notes' => [[1, 2, 3], [4, 5, 6]]]);

    $article = ChunkedArticle::find(1);

    expect($article->chunks)->toBe([[1.0, 0.0], [0.0, 0.5]])
        ->and($article->notes)->toBe([[1.0, 2.0, 3.0], [4.0, 5.0, 6.0]]);
});

it('stores no vectors for an empty array, a null and an omitted column', function () {
    ChunkedArticle::create(['id' => 1, 'title' => 'empty', 'chunks' => [], 'notes' => []]);
    ChunkedArticle::create(['id' => 2, 'title' => 'null', 'chunks' => null, 'notes' => null]);
    ChunkedArticle::create(['id' => 3, 'title' => 'omitted']);

    expect(ChunkedArticle::query()->orderBy('id')->get()->map(fn ($article) => [$article->chunks, $article->notes])->all())
        ->toBe([[[], []], [[], []], [[], []]]);
});

it('writes vectors through the query builder, without a cast', function () {
    chunkedTable()->insert(['id' => 1, 'title' => 'a', 'chunks' => [[0.5, 0.25]], 'notes' => [[7, 8]]]);

    expect(chunkedTable()->where('id', 1)->first(['chunks', 'notes']))
        ->toEqual((object) ['chunks' => '[[0.500000,0.250000]]', 'notes' => '[[7.000000,8.000000]]']);
});

it('finds a document by any of its vectors, once, at the distance of its closest one', function () {
    ChunkedArticle::create(['id' => 1, 'title' => 'near', 'chunks' => [[0, 1], [1, 0]]]);
    ChunkedArticle::create(['id' => 2, 'title' => 'far', 'chunks' => [[0, 3]]]);
    ChunkedArticle::create(['id' => 3, 'title' => 'none', 'chunks' => []]);

    $found = ChunkedArticle::query()->knn('chunks', [1, 0], 5)->selectRaw('id, knn_dist() as distance')->get();

    expect($found->pluck('id')->all())->toBe([1, 2])
        ->and((float) $found->first()->distance)->toBe(0.0);
});

it('rewrites the vectors of a knn column with replace()', function () {
    ChunkedArticle::create(['id' => 1, 'title' => 'a', 'chunks' => [[1, 0]]]);

    chunkedTable()->replace(['id' => 1, 'title' => 'a', 'chunks' => [[0, 1], [1, 1]]]);

    expect(ChunkedArticle::find(1)->chunks)->toBe([[0.0, 1.0], [1.0, 1.0]]);
});

it('updates a column without knn, and clears it with an empty array or a null', function () {
    ChunkedArticle::create(['id' => 1, 'title' => 'a', 'notes' => [[1, 2]]]);
    ChunkedArticle::create(['id' => 2, 'title' => 'b', 'notes' => [[3, 4]]]);

    chunkedTable()->where('id', 1)->update(['notes' => [[9, 9], [8, 8]]]);
    ChunkedArticle::find(2)->update(['notes' => null]);

    expect(ChunkedArticle::find(1)->notes)->toBe([[9.0, 9.0], [8.0, 8.0]])
        ->and(ChunkedArticle::find(2)->notes)->toBe([]);
});

it('writes a row whose only value is a vector array with named keys', function () {
    chunkedTable()->insert(['notes' => ['first' => [1, 2]]]);
    chunkedTable()->replace(['notes' => ['first' => [3, 4], 'second' => [5, 6]]]);

    expect(chunkedTable()->upsert(['notes' => ['first' => [7, 8]], 'id' => 99], 'id'))->toBe(1)
        ->and(chunkedTable()->orderBy('id')->pluck('notes')->map(fn ($notes) => json_decode($notes))->all())->toEqual([
            [[7, 8]],
            [[1, 2]],
            [[3, 4], [5, 6]],
        ]);
});

it('refuses knn() by document id on a float_vector_array', function () {
    ChunkedArticle::create(['id' => 1, 'title' => 'a', 'chunks' => [[1, 0]]]);

    expect(fn () => ChunkedArticle::query()->knn('chunks', 1, 5)->get())->toThrow(LogicException::class, 'knn() by document id');
});
