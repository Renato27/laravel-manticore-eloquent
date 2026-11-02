<?php

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ManticoreEloquent\Concerns\HasManticore;
use ManticoreEloquent\Eloquent\Casts\VectorCast;
use ManticoreEloquent\Eloquent\ManticoreModel;

/**
 * The README and the docs, run as printed: same classes, same calls, same migration. These
 * are the first lines of this package anyone copies, so they have to work. It also holds the
 * limits docs/known-limits.md describes; if one of those tests fails, that page is out of date.
 */

class DocsArticle extends ManticoreModel
{
    protected $table = 'docs_articles_rt';
    protected $guarded = [];
    protected $casts = ['published' => 'boolean'];
}

class DocsCompany extends Model
{
    use HasManticore;

    public function searchableAs(): string
    {
        return 'docs_companies_rt';
    }
}

class DocsUuidArticle extends ManticoreModel
{
    protected $table = 'docs_plain_rt';
    protected $guarded = [];
    public $timestamps = false;
    public $incrementing = false;
    protected $keyType = 'string';
}

class DocsVectorArticle extends ManticoreModel
{
    protected $table = 'docs_plain_rt';
    protected $guarded = [];
    public $timestamps = false;
    protected $casts = ['embedding' => VectorCast::class];
}

class DocsTimestampedArticle extends ManticoreModel
{
    protected $table = 'docs_stamped_rt';
    protected $guarded = [];
}

class DocsDatetimeArticle extends ManticoreModel
{
    protected $table = 'docs_stamped_rt';
    protected $guarded = [];
    public $timestamps = false;
    protected $casts = ['created_at' => 'datetime'];
}

class DocsStampedGatewayArticle extends Model
{
    use HasManticore;

    protected $table = 'docs_stamped_rt';
    protected $guarded = [];
}

function docsSchema(): \Illuminate\Database\Schema\Builder
{
    return Schema::connection('manticore');
}

function docsTable(string $table): \Illuminate\Database\Query\Builder
{
    return DB::connection('manticore')->table($table);
}

beforeEach(function () {
    try {
        DB::connection('manticore')->getPdo();
    } catch (\Throwable $e) {
        $this->markTestSkipped('Manticore not reachable on the MySQL port: ' . $e->getMessage());
    }

    foreach (['docs_articles_rt', 'docs_companies_rt', 'docs_plain_rt', 'docs_stamped_rt'] as $table) {
        docsSchema()->dropIfExists($table);
    }

    // docs/migrations.md
    docsSchema()->create('docs_articles_rt', function (Blueprint $table) {
        $table->text('body');
        $table->string('title');
        $table->integer('views');
        $table->bigInteger('author_id');
        $table->float('score');
        $table->boolean('published');
        $table->json('meta');
        $table->timestamps();
        $table->mva('tag_ids');
        $table->mva64('big_ids');
        $table->floatVector('embedding', dims: 384);
        $table->text('summary')->indexed()->stored();
        $table->minInfixLen(2);
        $table->morphology('stem_en');
    });
});

afterEach(function () {
    foreach (['docs_articles_rt', 'docs_companies_rt', 'docs_plain_rt', 'docs_stamped_rt'] as $table) {
        try {
            docsSchema()->dropIfExists($table);
        } catch (\Throwable) {
        }
    }
});

it('runs the README model example', function () {
    $article = DocsArticle::create(['title' => 'Hello', 'body' => 'laravel on manticore', 'published' => true]);
    expect($article->id)->toBeGreaterThan(0);
    expect(DocsArticle::find($article->id)->created_at->year)->toBeGreaterThan(2000);

    docsTable('docs_articles_rt')->insert(['id' => 10, 'title' => 'ten', 'body' => 'laravel again', 'published' => 1, 'created_at' => 1700000000]);

    expect(DocsArticle::where('published', true)->match('laravel')->orderBy('created_at', 'desc')->paginate()->total())->toBe(2);
    expect(DocsArticle::find(10)->title)->toBe('ten');

    DocsArticle::where('id', 10)->update(['published' => false]);
    expect(DocsArticle::find(10)->published)->toBeFalse();

    expect(DocsArticle::destroy(10))->toBe(1);
    expect(DocsArticle::find(10))->toBeNull();
});

it('runs the README gateway example', function () {
    docsSchema()->create('docs_companies_rt', function (Blueprint $table) {
        $table->text('name');
    });
    docsTable('docs_companies_rt')->insert(['id' => 1, 'name' => 'fintech one']);

    expect(DocsCompany::manticore()->match('fintech')->paginate()->total())->toBe(1);
});

it('runs the querying guide', function () {
    docsTable('docs_articles_rt')->insert(['id' => 1, 'title' => 'a', 'body' => 'cloud native', 'views' => 500]);

    expect(DocsArticle::query()->match('cloud native', 'body')->where('views', '>', 100)->option('ranker', 'sph04')->maxMatches(5000)->facet('title')->get()->modelKeys())->toBe([1]);
    expect(DocsArticle::query()->match('cloud')->option('ranker', DB::raw("expr('sum(lcs*user_weight)')"))->count())->toBe(1);
    expect(fn () => DocsArticle::query()->match('cloud')->option('ranker', 'not a bare word')->get())->toThrow(InvalidArgumentException::class);

    $row = DocsArticle::query()
        ->match('cloud native')
        ->highlight('body', ['before_match' => '<mark>', 'after_match' => '</mark>'])
        ->get()
        ->first();

    expect($row->highlight)->toContain('<mark>');
});

it('replaces through Eloquent, returning the builder as the guide says', function () {
    docsTable('docs_articles_rt')->insert(['id' => 1, 'title' => 'old', 'body' => 'old']);

    expect(DocsArticle::query()->replace(['id' => 1, 'title' => 'New', 'body' => '…']))->toBeInstanceOf(Builder::class);
    expect(DocsArticle::find(1)->title)->toBe('New');

    expect(docsTable('docs_articles_rt')->replace(['id' => 1, 'title' => 'Newer', 'body' => '…']))->toBeTrue();
    expect(fn () => DocsArticle::query()->replace(['id' => 1, 'no_such_column' => 'x']))->toThrow(QueryException::class);
});

it('runs the uuid guide', function () {
    docsSchema()->create('docs_plain_rt', function (Blueprint $table) {
        $table->uuid('id');
        $table->text('title');
        $table->integer('views');
    });

    $uuid = '550e8400-e29b-41d4-a716-446655440000';

    $article = DocsUuidArticle::create(['id' => $uuid, 'title' => 'Hello', 'views' => 1]);
    expect(DocsUuidArticle::find($uuid)->title)->toBe('Hello');
    expect(DocsUuidArticle::where('id', $uuid)->first()->id)->toBe($uuid);

    $article->update(['views' => 2]);
    expect(DocsUuidArticle::find($uuid)->views)->toBe(2);

    $article->delete();
    expect(DocsUuidArticle::find($uuid))->toBeNull();
});

it('runs the vector search guide', function () {
    docsSchema()->create('docs_plain_rt', function (Blueprint $table) {
        $table->floatVector('embedding', dims: 3);
    });

    DocsVectorArticle::create(['id' => 1, 'embedding' => [0.1, 0.2, 0.3]]);
    DocsVectorArticle::create(['id' => 2, 'embedding' => [0.9, 0.9, 0.9]]);

    expect(DocsVectorArticle::query()->where('id', 1)->first()->embedding)->toBe([0.1, 0.2, 0.3]);

    $scored = DocsVectorArticle::query()->knn('embedding', [0.1, 0.2, 0.3], k: 10)->selectRaw('*, knn_dist() as distance')->get();
    expect($scored->first()->id)->toBe(1);
    expect($scored->first()->distance)->not->toBeNull();

    expect(DocsVectorArticle::query()->knn('embedding', 1, k: 10)->get()->modelKeys())->toBe([2]);
    expect(DocsVectorArticle::query()->knn('embedding', [0.1, 0.2, 0.3], k: 10, ef: ['ef' => 2000, 'rescore' => true])->count())->toBe(2);

    docsTable('docs_plain_rt')->replace([(new DocsVectorArticle(['id' => 1, 'embedding' => [0.4, 0.5, 0.6]]))->getAttributes()]);
    expect(DocsVectorArticle::find(1)->embedding)->toBe([0.4, 0.5, 0.6]);
});

it('creates the vector column from the migrations guide', function () {
    docsSchema()->create('docs_plain_rt', function (Blueprint $table) {
        $table->floatVector('content_vector', dims: 768, similarity: 'cosine', knnOptions: [
            'hnsw_m' => 16,
            'hnsw_ef_construction' => 200,
            'quantization' => '8bit',
        ]);
    });

    expect(docsSchema()->getColumnListing('docs_plain_rt'))->toContain('content_vector');
});

/**
 * Timestamps, as docs/known-limits.md describes them.
 */
function createStampedTable(): void
{
    docsSchema()->create('docs_stamped_rt', function (Blueprint $table) {
        $table->text('title');
        $table->integer('views');
        $table->timestamps();
    });
}

function stampedRow(int $id): object
{
    return docsTable('docs_stamped_rt')->where('id', $id)->first();
}

it('stores Eloquent timestamps as Unix integers on create()', function () {
    createStampedTable();

    $before = time();
    $article = DocsTimestampedArticle::create(['id' => 1, 'title' => 'a', 'views' => 1]);

    expect(stampedRow(1)->created_at)->toBeGreaterThanOrEqual($before);
    expect(stampedRow(1)->updated_at)->toBeGreaterThanOrEqual($before);
    expect(DocsTimestampedArticle::find(1)->created_at->getTimestamp())->toBe($article->created_at->getTimestamp());
});

it('refreshes updated_at on a builder update()', function () {
    createStampedTable();
    docsTable('docs_stamped_rt')->insert(['id' => 1, 'title' => 'a', 'views' => 1, 'created_at' => 1000, 'updated_at' => 1000]);

    DocsTimestampedArticle::where('id', 1)->update(['views' => 2]);

    expect(stampedRow(1)->views)->toBe(2);
    expect(stampedRow(1)->updated_at)->toBeGreaterThan(1700000000);
    expect(stampedRow(1)->created_at)->toBe(1000);
});

it('refreshes updated_at on save() and touch()', function () {
    createStampedTable();
    docsTable('docs_stamped_rt')->insert(['id' => 1, 'title' => 'a', 'views' => 1, 'created_at' => 1000, 'updated_at' => 1000]);

    $article = DocsTimestampedArticle::find(1);
    $article->views = 3;
    $article->save();

    expect(stampedRow(1)->views)->toBe(3);
    expect(stampedRow(1)->updated_at)->toBeGreaterThan(1700000000);

    docsTable('docs_stamped_rt')->where('id', 1)->update(['updated_at' => 1000]);
    DocsTimestampedArticle::find(1)->touch();

    expect(stampedRow(1)->updated_at)->toBeGreaterThan(1700000000);
});

it('does not rewrite a document saved without changes', function () {
    createStampedTable();
    docsTable('docs_stamped_rt')->insert(['id' => 1, 'title' => 'a', 'views' => 1, 'created_at' => 1000, 'updated_at' => 1000]);

    DocsTimestampedArticle::find(1)->save();

    expect(stampedRow(1)->updated_at)->toBe(1000);
});

it('writes a datetime cast as a Unix integer and reads it back as a date', function () {
    createStampedTable();

    DocsDatetimeArticle::create(['id' => 2, 'title' => 'b', 'created_at' => '2026-01-02 03:04:05']);

    $expected = Illuminate\Support\Carbon::parse('2026-01-02 03:04:05')->getTimestamp();

    expect(stampedRow(2)->created_at)->toBe($expected);
    expect(DocsDatetimeArticle::find(2)->created_at->format('Y-m-d H:i:s'))->toBe('2026-01-02 03:04:05');
});

it('keeps the same instant when the application is not in UTC', function () {
    createStampedTable();

    $timezone = date_default_timezone_get();
    date_default_timezone_set('America/Sao_Paulo');

    try {
        DocsDatetimeArticle::create(['id' => 2, 'title' => 'b', 'created_at' => '2026-01-02 03:04:05']);

        expect(stampedRow(2)->created_at)->toBe(gmmktime(6, 4, 5, 1, 2, 2026));
        expect(DocsDatetimeArticle::find(2)->created_at->format('Y-m-d H:i:s'))->toBe('2026-01-02 03:04:05');
    } finally {
        date_default_timezone_set($timezone);
    }
});

it('sends dates in queries as Unix integers', function () {
    createStampedTable();

    docsTable('docs_stamped_rt')->insert(['id' => 1, 'title' => 'a', 'created_at' => now()]);
    docsTable('docs_stamped_rt')->insert(['id' => 2, 'title' => 'b', 'created_at' => now()->subYears(2)]);

    expect(docsTable('docs_stamped_rt')->where('created_at', '>', now()->subDay())->pluck('id')->all())->toBe([1]);

    docsTable('docs_stamped_rt')->where('id', 2)->update(['created_at' => now()]);

    expect(stampedRow(2)->created_at)->toBeGreaterThan(1700000000);
});

it('writes timestamps through the HasManticore gateway', function () {
    createStampedTable();

    DocsStampedGatewayArticle::manticore()->create(['id' => 1, 'title' => 'a', 'views' => 1]);

    expect(stampedRow(1)->created_at)->toBeGreaterThan(1700000000);

    DocsStampedGatewayArticle::manticore()->where('id', 1)->update(['views' => 2]);

    expect(stampedRow(1)->views)->toBe(2);
});

it('fails with an error when the table has no updated_at', function () {
    docsSchema()->create('docs_plain_rt', function (Blueprint $table) {
        $table->text('title');
        $table->timestamp('created_at');
    });

    expect(fn () => (new DocsTimestampedArticle)->setTable('docs_plain_rt')->newQuery()->create(['id' => 1, 'title' => 'a']))
        ->toThrow(QueryException::class, 'updated_at');
});

it('reads a document without a timestamp as having none', function () {
    createStampedTable();
    docsTable('docs_stamped_rt')->insert(['id' => 1, 'title' => 'a']);

    expect(DocsTimestampedArticle::find(1)->created_at)->toBeNull();
});
