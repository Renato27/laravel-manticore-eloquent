<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use ManticoreEloquent\Eloquent\ManticoreModel;

/**
 * End-to-end tests against a real Manticore instance (MySQL protocol, port 9306).
 * Skipped automatically when no server is reachable.
 *
 *   docker run --rm -p 9306:9306 -p 9308:9308 manticoresearch/manticore
 */

class IntegrationArticle extends ManticoreModel
{
    protected $table = 'it_articles_rt';
    protected $guarded = [];
    public $timestamps = false;
}

beforeEach(function () {
    try {
        DB::connection('manticore')->getPdo();
    } catch (\Throwable $e) {
        $this->markTestSkipped('Manticore not reachable on the MySQL port: ' . $e->getMessage());
    }

    Schema::connection('manticore')->dropIfExists('it_articles_rt');
    Schema::connection('manticore')->create('it_articles_rt', function (Blueprint $table) {
        $table->text('title');
        $table->integer('views');
        $table->boolean('published');
    });
});

afterAll(function () {
    try {
        Schema::connection('manticore')->dropIfExists('it_articles_rt');
    } catch (\Throwable) {
    }
});

it('creates, searches, updates and deletes documents', function () {
    IntegrationArticle::create(['id' => 1, 'title' => 'Laravel meets Manticore', 'views' => 10, 'published' => true]);
    IntegrationArticle::create(['id' => 2, 'title' => 'Pure full text search', 'views' => 5, 'published' => false]);

    expect(IntegrationArticle::count())->toBe(2);

    $hit = IntegrationArticle::query()->match('manticore')->first();
    expect($hit)->not->toBeNull();
    expect($hit->id)->toBe(1);

    IntegrationArticle::query()->where('id', 2)->update(['views' => 99]);
    expect(IntegrationArticle::query()->where('id', 2)->value('views'))->toBe(99);

    IntegrationArticle::query()->where('id', 1)->delete();
    expect(IntegrationArticle::count())->toBe(1);
});

it('paginates beyond the default max_matches cap', function () {
    for ($i = 1; $i <= 30; $i++) {
        IntegrationArticle::create(['id' => $i, 'title' => "doc {$i}", 'views' => $i, 'published' => true]);
    }

    $page = IntegrationArticle::query()->orderBy('views')->paginate(perPage: 10, page: 2);

    expect($page->count())->toBe(10);
    expect($page->total())->toBe(30);
});

it('rewrites a full document with replace()', function () {
    IntegrationArticle::create(['id' => 7, 'title' => 'first revision', 'views' => 1, 'published' => false]);

    IntegrationArticle::query()->replace([
        'id'        => 7,
        'title'     => 'second revision',
        'views'     => 2,
        'published' => true,
    ]);

    $doc = IntegrationArticle::query()->where('id', 7)->first();

    expect($doc->title)->toBe('second revision');
    expect($doc->views)->toBe(2);
    expect(IntegrationArticle::query()->match('revision')->count())->toBe(1);
});

it('replaces several documents at once', function () {
    IntegrationArticle::query()->replace([
        ['id' => 10, 'title' => 'bulk one', 'views' => 1, 'published' => true],
        ['id' => 11, 'title' => 'bulk two', 'views' => 2, 'published' => true],
    ]);

    expect(IntegrationArticle::query()->match('bulk')->count())->toBe(2);
});

it('runs a knn vector search ordered by distance', function () {
    $conn = DB::connection('manticore');

    Schema::connection('manticore')->dropIfExists('it_vectors_rt');
    Schema::connection('manticore')->create('it_vectors_rt', function (Blueprint $table) {
        $table->floatVector('embedding', 4);
    });

    // float_vector literals aren't expressible as bindings, so seed them with raw SQL.
    $conn->statement('insert into it_vectors_rt (id, embedding) values (1, (0.1,0.1,0.1,0.1))');
    $conn->statement('insert into it_vectors_rt (id, embedding) values (2, (0.9,0.9,0.9,0.9))');

    $ids = $conn->table('it_vectors_rt')
        ->knn('embedding', [0.1, 0.1, 0.1, 0.1], 2)
        ->pluck('id')
        ->all();

    expect($ids)->toContain(1);
    expect($ids[0])->toBe(1); // nearest neighbour first

    Schema::connection('manticore')->dropIfExists('it_vectors_rt');
});

it('returns a HIGHLIGHT() column for matched documents', function () {
    IntegrationArticle::create(['id' => 1, 'title' => 'Laravel meets Manticore', 'views' => 1, 'published' => true]);

    $row = DB::connection('manticore')->table('it_articles_rt')
        ->match('manticore')
        ->highlight('title')
        ->where('id', 1)
        ->first();

    expect($row->highlight)->toContain('<b>');
    expect($row->highlight)->toContain('Manticore');
});

it('honors min_infix_len from the table-option macros', function () {
    Schema::connection('manticore')->dropIfExists('it_opts_rt');
    Schema::connection('manticore')->create('it_opts_rt', function (Blueprint $table) {
        $table->text('body')->indexed()->stored();
        $table->minInfixLen(2);
    });

    DB::connection('manticore')->table('it_opts_rt')->insert(['id' => 1, 'body' => 'manticore']);

    // Infix wildcards only resolve because min_infix_len was set on the table.
    $count = DB::connection('manticore')->table('it_opts_rt')->match('*anti*')->count();

    expect($count)->toBe(1);

    Schema::connection('manticore')->dropIfExists('it_opts_rt');
});

it('introspects indexes through the schema builder', function () {
    $builder = DB::connection('manticore')->getSchemaBuilder();

    expect($builder->hasTable('it_articles_rt'))->toBeTrue();
    expect($builder->hasTable('does_not_exist_rt'))->toBeFalse();

    $columns = $builder->getColumnListing('it_articles_rt');

    expect($columns)->toContain('title');
    expect($columns)->toContain('views');
    expect($columns)->toContain('published');
});

it('creates an index using the Manticore Blueprint macros', function () {
    Schema::connection('manticore')->dropIfExists('it_macros_rt');

    Schema::connection('manticore')->create('it_macros_rt', function (Blueprint $table) {
        $table->text('title');
        $table->mva('tag_ids');
        $table->mva64('big_ids');
        $table->floatVector('embedding', 4);
    });

    $columns = DB::connection('manticore')->getSchemaBuilder()->getColumnListing('it_macros_rt');

    expect($columns)->toContain('tag_ids');
    expect($columns)->toContain('big_ids');
    expect($columns)->toContain('embedding');

    Schema::connection('manticore')->dropIfExists('it_macros_rt');
});
