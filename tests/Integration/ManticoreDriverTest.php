<?php

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ManticoreEloquent\Concerns\HasManticore;
use ManticoreEloquent\Eloquent\Casts\VectorCast;
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

class IntegrationGatewayArticle extends Model
{
    use HasManticore;

    protected $table = 'it_articles_rt';

    public $timestamps = false;
}

class IntegrationVector extends ManticoreModel
{
    protected $table = 'it_vectors_rt';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = ['embedding' => VectorCast::class];
}

beforeEach(function () {
    try {
        DB::connection('manticore')->getPdo();
    } catch (Throwable $e) {
        $this->markTestSkipped('Manticore not reachable on the MySQL port: '.$e->getMessage());
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
    } catch (Throwable) {
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

it('finds a document by its integer key', function () {
    IntegrationArticle::create(['id' => 1, 'title' => 'one', 'views' => 1, 'published' => true]);
    IntegrationArticle::create(['id' => 2, 'title' => 'two', 'views' => 2, 'published' => true]);

    expect(IntegrationArticle::find(2)->title)->toBe('two');
    expect(IntegrationArticle::find(99))->toBeNull();
    expect(IntegrationArticle::findMany([1, 2])->modelKeys())->toEqualCanonicalizing([1, 2]);
});

it('finds a document through the HasManticore gateway', function () {
    IntegrationArticle::create(['id' => 1, 'title' => 'one', 'views' => 1, 'published' => true]);

    expect(IntegrationGatewayArticle::manticore()->find(1)->title)->toBe('one');
    expect(IntegrationGatewayArticle::manticore()->find(99))->toBeNull();
});

it('tells whether matching documents exist', function () {
    IntegrationArticle::create(['id' => 1, 'title' => 'one', 'views' => 1, 'published' => true]);

    expect(IntegrationArticle::query()->where('id', 1)->exists())->toBeTrue();
    expect(IntegrationArticle::query()->where('id', 2)->exists())->toBeFalse();
    expect(IntegrationArticle::query()->match('one')->doesntExist())->toBeFalse();
    expect(DB::connection('manticore')->table('it_articles_rt')->where('views', 99)->doesntExist())->toBeTrue();
});

it('runs chunkById() on an integer table', function () {
    foreach ([1, 2, 3] as $id) {
        IntegrationArticle::create(['id' => $id, 'title' => "doc {$id}", 'views' => $id, 'published' => true]);
    }

    $seen = [];
    IntegrationArticle::query()->chunkById(2, function ($rows) use (&$seen) {
        array_push($seen, ...$rows->modelKeys());
    });

    expect($seen)->toBe([1, 2, 3]);
});

it('walks an integer table with lazyById()', function () {
    foreach ([1, 2, 3] as $id) {
        IntegrationArticle::create(['id' => $id, 'title' => "doc {$id}", 'views' => $id, 'published' => true]);
    }

    expect(IntegrationArticle::query()->lazyById(2)->map(fn ($doc) => $doc->id)->all())->toBe([1, 2, 3]);
    expect(IntegrationArticle::query()->lazyByIdDesc(2)->map(fn ($doc) => $doc->id)->all())->toBe([3, 2, 1]);
});

it('paginates with the total of the table', function () {
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
        'id' => 7,
        'title' => 'second revision',
        'views' => 2,
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

    IntegrationVector::create(['id' => 1, 'embedding' => [0.1, 0.1, 0.1, 0.1]]);
    IntegrationVector::create(['id' => 2, 'embedding' => [0.9, 0.9, 0.9, 0.9]]);

    expect(IntegrationVector::query()->where('id', 2)->first()->embedding)->toBe([0.9, 0.9, 0.9, 0.9]);

    $ids = $conn->table('it_vectors_rt')
        ->knn('embedding', [0.1, 0.1, 0.1, 0.1], 2)
        ->pluck('id')
        ->all();

    expect($ids)->toContain(1);
    expect($ids[0])->toBe(1);

    $similar = $conn->table('it_vectors_rt')
        ->knn('embedding', 2, 2, ['ef' => 2000, 'rescore' => true])
        ->pluck('id')
        ->all();

    expect($similar)->toBe([1]);

    $oversampled = $conn->table('it_vectors_rt')
        ->knn('embedding', [0.1, 0.1, 0.1, 0.1], 2, ['oversampling' => 3.0])
        ->pluck('id')
        ->all();

    expect($oversampled)->toContain(1);

    Schema::connection('manticore')->dropIfExists('it_vectors_rt');
});

it('runs knn() by a numeric string document id', function () {
    Schema::connection('manticore')->dropIfExists('it_vectors_rt');
    Schema::connection('manticore')->create('it_vectors_rt', function (Blueprint $table) {
        $table->floatVector('embedding', 2);
    });

    try {
        IntegrationVector::create(['id' => 1, 'embedding' => [0.1, 0.1]]);
        IntegrationVector::create(['id' => 2, 'embedding' => [0.9, 0.9]]);

        expect(DB::connection('manticore')->table('it_vectors_rt')->knn('embedding', '2', 2)->pluck('id')->all())
            ->toBe([1]);
    } finally {
        Schema::connection('manticore')->dropIfExists('it_vectors_rt');
    }
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

    $count = DB::connection('manticore')->table('it_opts_rt')->match('*anti*')->count();

    expect($count)->toBe(1);

    Schema::connection('manticore')->dropIfExists('it_opts_rt');
});

it('reports indexes of a live table', function () {
    withUuidTable(function () {
        $types = array_column(
            DB::connection('manticore')->getSchemaBuilder()->getIndexes('it_uuid_articles_rt'),
            'columns',
            'type'
        );

        expect($types)->toBe(['primary' => ['id'], 'knn' => ['embedding'], 'fulltext' => ['title']]);
    });
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

it('partially replaces only the given fields, including full-text ones', function () {
    $schema = Schema::connection('manticore');
    $schema->dropIfExists('t1_docs');
    $schema->create('t1_docs', function (Blueprint $table) {
        $table->text('title');
        $table->text('tag');
        $table->integer('price');
    });

    try {
        $docs = DB::connection('manticore')->table('t1_docs');
        $docs->insert(['id' => 1, 'title' => 'first title', 'tag' => 'alpha', 'price' => 10]);

        expect($docs->replaceWhereId(1, ['tag' => 'beta']))->toBeTrue();

        $doc = DB::connection('manticore')->table('t1_docs')->where('id', 1)->first();
        expect($doc->title)->toBe('first title');
        expect($doc->price)->toBe(10);
        expect($doc->tag)->toBe('beta');

        DB::connection('manticore')->table('t1_docs')->replaceWhereId(1, ['title' => 'second heading']);

        expect(DB::connection('manticore')->table('t1_docs')->match('heading')->count())->toBe(1);
        expect(DB::connection('manticore')->table('t1_docs')->match('first')->count())->toBe(0);
        expect(DB::connection('manticore')->table('t1_docs')->where('id', 1)->value('tag'))->toBe('beta');
    } finally {
        $schema->dropIfExists('t1_docs');
    }
});

/**
 * UUID document ids. Plain CRUD is native to Manticore; partial REPLACE and KNN by document
 * id are not, because Buddy only takes numeric ids there (see the README).
 */
class IntegrationUuidArticle extends ManticoreModel
{
    protected $table = 'it_uuid_articles_rt';

    protected $guarded = [];

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $casts = ['embedding' => VectorCast::class];
}

class IntegrationHasUuidsArticle extends ManticoreModel
{
    use HasUuids;

    protected $table = 'it_uuid_articles_rt';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = ['embedding' => VectorCast::class];
}

/**
 * Create the uuid-keyed table, run the test body, and always drop the table afterwards.
 */
function withUuidTable(callable $body): void
{
    $schema = Schema::connection('manticore');
    $schema->dropIfExists('it_uuid_articles_rt');
    $schema->create('it_uuid_articles_rt', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->text('title');
        $table->string('tag');
        $table->integer('views');
        $table->floatVector('embedding', 2);
    });

    try {
        $body();
    } finally {
        $schema->dropIfExists('it_uuid_articles_rt');
    }
}

it('creates a table whose document id is a uuid', function () {
    withUuidTable(function () {
        $rows = DB::connection('manticore')->select('DESC it_uuid_articles_rt');

        expect($rows[0]->Field)->toBe('id');
        expect($rows[0]->Type)->toBe('uuid');
    });
});

it('runs the Eloquent cycle on a uuid key', function () {
    withUuidTable(function () {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';

        $created = IntegrationUuidArticle::create([
            'id' => $uuid, 'title' => 'Mechanical keyboard', 'tag' => 'a', 'views' => 1, 'embedding' => [0.1, 0.2],
        ]);
        expect($created->getKey())->toBe($uuid);

        $found = IntegrationUuidArticle::find($uuid);
        expect($found)->not->toBeNull();
        expect($found->getKey())->toBe($uuid);
        expect($found->title)->toBe('Mechanical keyboard');

        expect(IntegrationUuidArticle::query()->where('id', $uuid)->first()->id)->toBe($uuid);
        expect(IntegrationUuidArticle::query()->match('keyboard')->first()->id)->toBe($uuid);

        $found->update(['views' => 99]);
        expect(IntegrationUuidArticle::find($uuid)->views)->toBe(99);

        $found->delete();
        expect(IntegrationUuidArticle::find($uuid))->toBeNull();
    });
});

it('finds several uuid keys at once', function () {
    withUuidTable(function () {
        foreach (['550e8400-e29b-41d4-a716-446655440000', '660e8400-e29b-41d4-a716-446655440001', '770e8400-e29b-41d4-a716-446655440002'] as $i => $uuid) {
            IntegrationUuidArticle::create(['id' => $uuid, 'title' => "doc {$i}", 'tag' => 'a', 'views' => $i, 'embedding' => [0.1, 0.2]]);
        }

        $ids = IntegrationUuidArticle::findMany(['550e8400-e29b-41d4-a716-446655440000', '770e8400-e29b-41d4-a716-446655440002'])
            ->modelKeys();

        expect($ids)->toEqualCanonicalizing(['550e8400-e29b-41d4-a716-446655440000', '770e8400-e29b-41d4-a716-446655440002']);
    });
});

it('finds a uuid whatever its case and returns it in lowercase', function () {
    withUuidTable(function () {
        IntegrationUuidArticle::create(['id' => '660E8400-E29B-41D4-A716-446655440001', 'title' => 'upper', 'tag' => 'a', 'views' => 1, 'embedding' => [0.1, 0.2]]);

        expect(IntegrationUuidArticle::find('660E8400-E29B-41D4-A716-446655440001')->id)
            ->toBe('660e8400-e29b-41d4-a716-446655440001');
    });
});

it('generates the uuid on the client with the HasUuids trait', function () {
    withUuidTable(function () {
        $created = IntegrationHasUuidsArticle::create(['title' => 'generated', 'tag' => 'a', 'views' => 1, 'embedding' => [0.1, 0.2]]);

        expect($created->getKey())->toBeString()->not->toBeEmpty();
        expect(IntegrationHasUuidsArticle::find($created->getKey())->title)->toBe('generated');
    });
});

it('rewrites a whole document by uuid with replace()', function () {
    withUuidTable(function () {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        IntegrationUuidArticle::create(['id' => $uuid, 'title' => 'first', 'tag' => 'a', 'views' => 1, 'embedding' => [0.1, 0.2]]);

        IntegrationUuidArticle::query()->toBase()->replace(
            (new IntegrationUuidArticle(['id' => $uuid, 'title' => 'second', 'tag' => 'b', 'views' => 2, 'embedding' => [0.1, 0.2]]))->getAttributes()
        );

        expect(IntegrationUuidArticle::query()->count())->toBe(1);
        expect(IntegrationUuidArticle::find($uuid)->title)->toBe('second');
    });
});

/**
 * Create a table with every attribute type, run the test body, and always drop the table.
 */
function withAllTypesUuidTable(callable $body): void
{
    $schema = Schema::connection('manticore');
    $schema->dropIfExists('it_uuid_types_rt');
    $schema->create('it_uuid_types_rt', function (Blueprint $table) {
        $table->uuid('id');
        $table->text('title');
        $table->string('tag');
        $table->integer('views');
        $table->bigInteger('big');
        $table->float('score');
        $table->boolean('published');
        $table->json('meta');
        $table->timestamp('seen_at');
        $table->mva('tag_ids');
        $table->mva64('big_ids');
        $table->floatVector('embedding', 2);
    });

    try {
        $body(DB::connection('manticore'));
    } finally {
        $schema->dropIfExists('it_uuid_types_rt');
    }
}

it('partially replaces a uuid document, keeping every other field intact', function () {
    withAllTypesUuidTable(function ($db) {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';

        $db->statement("insert into it_uuid_types_rt (id, title, tag, views, big, score, published, meta, seen_at, tag_ids, big_ids, embedding) values ('{$uuid}', 'it\\'s é first', 'alpha', 5, 9000000000, 1.5, 1, '{\"a\":[1,2],\"q\":\"it\\'s\"}', 1700000000, (3,1,2), (9000000000,1), (0.25,0.5))");

        expect($db->table('it_uuid_types_rt')->replaceWhereId($uuid, ['tag' => 'beta']))->toBeTrue();

        $doc = $db->table('it_uuid_types_rt')->where('id', $uuid)->first();

        expect($doc->tag)->toBe('beta');
        expect($doc->title)->toBe("it's é first");
        expect($doc->views)->toBe(5);
        expect($doc->big)->toBe(9000000000);
        expect((float) $doc->score)->toBe(1.5);
        expect($doc->published)->toBe(1);
        expect(json_decode($doc->meta, true))->toBe(['a' => [1, 2], 'q' => "it's"]);
        expect($doc->seen_at)->toBe(1700000000);
        expect($doc->tag_ids)->toBe('1,2,3');
        expect($doc->big_ids)->toBe('1,9000000000');
        expect(array_map('floatval', explode(',', $doc->embedding)))->toBe([0.25, 0.5]);
        expect($db->table('it_uuid_types_rt')->count())->toBe(1);
    });
});

it('rewrites a full-text field by uuid, which update() cannot do', function () {
    withUuidTable(function () {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        IntegrationUuidArticle::create(['id' => $uuid, 'title' => 'first heading', 'tag' => 'alpha', 'views' => 7, 'embedding' => [0.25, 0.5]]);

        $table = fn () => DB::connection('manticore')->table('it_uuid_articles_rt');

        $table()->replaceWhereId($uuid, ['title' => 'second heading']);

        expect($table()->match('second')->count())->toBe(1);
        expect($table()->match('first')->count())->toBe(0);
        expect(IntegrationUuidArticle::find($uuid)->tag)->toBe('alpha');
    });
});

it('keeps empty attributes empty when partially replacing by uuid', function () {
    withAllTypesUuidTable(function ($db) {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $db->table('it_uuid_types_rt')->insert(['id' => $uuid, 'title' => 'minimal']);

        $db->table('it_uuid_types_rt')->replaceWhereId($uuid, ['tag' => 'beta']);

        $doc = $db->table('it_uuid_types_rt')->where('id', $uuid)->first();
        expect($doc->tag)->toBe('beta');
        expect($doc->title)->toBe('minimal');
        expect($doc->tag_ids)->toBe('');
        expect($doc->embedding)->toBe('');
    });
});

it('creates a document holding just the given fields on an unknown uuid, like an integer id', function () {
    withAllTypesUuidTable(function ($db) {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';

        $db->table('it_uuid_types_rt')->replaceWhereId($uuid, ['tag' => 'beta']);

        $doc = $db->table('it_uuid_types_rt')->where('id', $uuid)->first();
        expect($doc->tag)->toBe('beta');
        expect($doc->title)->toBe('');
    });
});

it('refuses a partial replace by uuid when a text field is not stored, leaving the document alone', function () {
    $schema = Schema::connection('manticore');
    $schema->dropIfExists('it_uuid_nostore_rt');
    $schema->create('it_uuid_nostore_rt', function (Blueprint $table) {
        $table->uuid('id');
        $table->text('body')->indexed();
        $table->string('tag');
    });

    try {
        $db = DB::connection('manticore');
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $db->table('it_uuid_nostore_rt')->insert(['id' => $uuid, 'body' => 'searchable words', 'tag' => 'alpha']);

        expect(fn () => $db->table('it_uuid_nostore_rt')->replaceWhereId($uuid, ['tag' => 'beta']))
            ->toThrow(RuntimeException::class, 'body');

        expect($db->table('it_uuid_nostore_rt')->where('id', $uuid)->value('tag'))->toBe('alpha');
        expect($db->table('it_uuid_nostore_rt')->match('searchable')->count())->toBe(1);
    } finally {
        $schema->dropIfExists('it_uuid_nostore_rt');
    }
});

/**
 * A source document, one near it and one far from it.
 */
function seedUuidNeighbours(): array
{
    $source = '550e8400-e29b-41d4-a716-446655440000';
    $near = '660e8400-e29b-41d4-a716-446655440001';
    $far = '770e8400-e29b-41d4-a716-446655440002';

    IntegrationUuidArticle::create(['id' => $source, 'title' => 'source', 'tag' => 'a', 'views' => 1, 'embedding' => [0.1, 0.2]]);
    IntegrationUuidArticle::create(['id' => $near, 'title' => 'near', 'tag' => 'b', 'views' => 2, 'embedding' => [0.1, 0.3]]);
    IntegrationUuidArticle::create(['id' => $far, 'title' => 'far', 'tag' => 'a', 'views' => 3, 'embedding' => [5.0, 5.0]]);

    return [$source, $near, $far];
}

it('finds the neighbours of a uuid document, never the document itself', function () {
    withUuidTable(function () {
        [$source, $near, $far] = seedUuidNeighbours();

        $ids = DB::connection('manticore')->table('it_uuid_articles_rt')
            ->knn('embedding', $source, k: 10)
            ->pluck('id')
            ->all();

        expect($ids)->toBe([$near, $far]);
    });
});

it('runs knn() by uuid through Eloquent models', function () {
    withUuidTable(function () {
        [$source, $near, $far] = seedUuidNeighbours();

        $found = IntegrationUuidArticle::query()->knn('embedding', $source, k: 10)->get();

        expect($found->modelKeys())->toBe([$near, $far]);
        expect($found->first())->toBeInstanceOf(IntegrationUuidArticle::class);
    });
});

it('keeps other filters, the option block and knn_dist() on knn() by uuid', function () {
    withUuidTable(function () {
        [$source, , $far] = seedUuidNeighbours();

        $rows = DB::connection('manticore')->table('it_uuid_articles_rt')
            ->selectRaw('id, knn_dist() as distance')
            ->knn('embedding', $source, 10, ['ef' => 2000])
            ->where('tag', 'a')
            ->get();

        expect($rows->pluck('id')->all())->toBe([$far]);
        expect((float) $rows->first()->distance)->toBeGreaterThan(0.0);
    });
});

it('counts and streams knn() by uuid', function () {
    withUuidTable(function () {
        [$source, $near, $far] = seedUuidNeighbours();

        $query = fn () => DB::connection('manticore')->table('it_uuid_articles_rt')->knn('embedding', $source, k: 10);

        expect($query()->count())->toBe(2);
        expect($query()->cursor()->pluck('id')->all())->toBe([$near, $far]);

        $page = $query()->paginate(perPage: 1);
        expect($page->total())->toBe(2);
        expect($page->count())->toBe(1);
    });
});

it('returns nothing from knn() by a uuid that does not exist', function () {
    withUuidTable(function () {
        seedUuidNeighbours();

        $query = fn () => DB::connection('manticore')->table('it_uuid_articles_rt')
            ->knn('embedding', '880e8400-e29b-41d4-a716-446655440003', k: 10);

        expect($query()->get()->all())->toBe([]);
        expect($query()->count())->toBe(0);
        expect($query()->cursor()->all())->toBe([]);
    });
});

it('returns nothing from knn() by a uuid document without a vector', function () {
    withUuidTable(function () {
        seedUuidNeighbours();

        $uuid = '880e8400-e29b-41d4-a716-446655440003';
        DB::connection('manticore')->table('it_uuid_articles_rt')->insert(['id' => $uuid, 'title' => 'no vector']);

        expect(DB::connection('manticore')->table('it_uuid_articles_rt')->knn('embedding', $uuid, k: 10)->get()->all())->toBe([]);
    });
});

it('tells whether a knn() by uuid has neighbours', function () {
    withUuidTable(function () {
        [$source] = seedUuidNeighbours();

        $table = fn () => DB::connection('manticore')->table('it_uuid_articles_rt');

        expect($table()->knn('embedding', $source, k: 10)->exists())->toBeTrue();
        expect($table()->knn('embedding', '880e8400-e29b-41d4-a716-446655440003', k: 10)->exists())->toBeFalse();
    });
});

it('filters a uuid id with !=, and orders by it', function () {
    withUuidTable(function () {
        [$source, $near, $far] = seedUuidNeighbours();

        $ids = IntegrationUuidArticle::query()->where('id', '!=', $source)->orderBy('id')->pluck('id')->all();

        expect($ids)->toBe([$near, $far]);
    });
});

it('rejects a range filter on a uuid id', function () {
    withUuidTable(function () {
        seedUuidNeighbours();

        expect(fn () => IntegrationUuidArticle::query()->where('id', '>', '550e8400-e29b-41d4-a716-446655440000')->get())
            ->toThrow(QueryException::class, 'range filters');
    });
});

it('cannot run chunkById() on a uuid table', function () {
    withUuidTable(function () {
        seedUuidNeighbours();

        expect(fn () => IntegrationUuidArticle::query()->chunkById(1, fn () => null))
            ->toThrow(QueryException::class);
    });
});

it('chunks a uuid table with chunk()', function () {
    withUuidTable(function () {
        seedUuidNeighbours();

        $seen = 0;
        IntegrationUuidArticle::query()->orderBy('id')->chunk(2, function ($rows) use (&$seen) {
            $seen += $rows->count();
        });

        expect($seen)->toBe(3);
    });
});

it('returns a server-generated uuid from insertGetId()', function () {
    withUuidTable(function () {
        $table = DB::connection('manticore')->table('it_uuid_articles_rt');

        $uuid = $table->insertGetId(['title' => 'generated']);

        expect($uuid)->toBeString()->toMatch('/^[0-9a-f-]{36}$/')
            ->and($table->where('id', $uuid)->value('title'))->toBe('generated');
    });
});

it('gives a model the uuid the server generated, and keeps one given by hand', function () {
    withUuidTable(function () {
        $model = new class extends ManticoreModel
        {
            protected $table = 'it_uuid_articles_rt';

            protected $guarded = [];

            public $timestamps = false;

            protected $keyType = 'string';
        };

        $generated = $model->create(['title' => 'generated']);
        $given = $model->create(['id' => '550e8400-e29b-41d4-a716-446655440000', 'title' => 'given']);

        expect($generated->getKey())->toMatch('/^[0-9a-f-]{36}$/')
            ->and($model->find($generated->getKey())->title)->toBe('generated')
            ->and($given->getKey())->toBe('550e8400-e29b-41d4-a716-446655440000');
    });
});

it('keeps returning a numeric id as an int', function () {
    $table = DB::connection('manticore')->table('it_articles_rt');
    $generated = $table->insertGetId(['title' => 'generated', 'views' => 1, 'published' => 1]);

    expect($generated)->toBeInt()->toBeGreaterThan(0)
        ->and($table->insertGetId(['id' => 77, 'title' => 'given', 'views' => 1, 'published' => 1]))->toBe(77);
});

it('rejects the uuid type anywhere but the id column', function () {
    expect(fn () => Schema::connection('manticore')->create('it_uuid_bad_rt', function (Blueprint $table) {
        $table->uuid('guid');
        $table->text('title');
    }))->toThrow(QueryException::class, "can only be used for 'id'");
});

it('searches several indexes through searchableAs()', function () {
    $schema = Schema::connection('manticore');

    foreach (['it_multi_a', 'it_multi_b'] as $table) {
        $schema->dropIfExists($table);
        $schema->create($table, fn (Blueprint $blueprint) => $blueprint->text('title'));
    }

    try {
        DB::connection('manticore')->table('it_multi_a')->insert(['id' => 1, 'title' => 'alpha']);
        DB::connection('manticore')->table('it_multi_b')->insert(['id' => 2, 'title' => 'alpha']);

        $model = new class extends Model
        {
            use HasManticore;

            public function searchableAs(): array
            {
                return ['it_multi_a', 'it_multi_b'];
            }
        };

        expect($model::manticore()->getQuery()->match('alpha')->orderBy('id')->pluck('id')->all())->toBe([1, 2]);
    } finally {
        foreach (['it_multi_a', 'it_multi_b'] as $table) {
            $schema->dropIfExists($table);
        }
    }
});

function withJsonTable(Closure $test): void
{
    $schema = Schema::connection('manticore');
    $schema->dropIfExists('it_json');
    $schema->create('it_json', function (Blueprint $table) {
        $table->json('meta');
        $table->integer('views');
    });

    try {
        $test(fn () => DB::connection('manticore')->table('it_json'));
    } finally {
        $schema->dropIfExists('it_json');
    }
}

it('filters by a json path', function () {
    withJsonTable(function (Closure $table) {
        $table()->insert(['id' => 1, 'meta' => '{"a":1,"s":"x","b":{"c":2},"f":1.5}', 'views' => 1]);
        $table()->insert(['id' => 2, 'meta' => '{"a":2,"s":"y","b":{"c":3},"f":2.5}', 'views' => 2]);

        expect($table()->where('meta->a', 1)->pluck('id')->all())->toBe([1])
            ->and($table()->where('meta->a', '>', 1)->pluck('id')->all())->toBe([2])
            ->and($table()->where('meta->s', 'y')->pluck('id')->all())->toBe([2])
            ->and($table()->where('meta->b->c', 3)->pluck('id')->all())->toBe([2])
            ->and($table()->where('meta->f', '>', 2.0)->pluck('id')->all())->toBe([2])
            ->and($table()->whereIn('meta->a', [1, 2])->count())->toBe(2)
            ->and($table()->whereNotNull('meta->s')->count())->toBe(2)
            ->and($table()->whereNull('meta->nope')->count())->toBe(2)
            ->and($table()->orderBy('meta->a', 'desc')->pluck('id')->all())->toBe([2, 1]);
    });
});

it('selects a json path', function () {
    withJsonTable(function (Closure $table) {
        $table()->insert(['id' => 1, 'meta' => '{"a":7}', 'views' => 1]);

        expect((int) $table()->select('meta->a as a')->first()->a)->toBe(7);
    });
});

it('updates a number at a json path', function () {
    withJsonTable(function (Closure $table) {
        $table()->insert(['id' => 1, 'meta' => '{"a":1,"b":{"c":2},"f":1.5,"on":1}', 'views' => 1]);

        $table()->where('id', 1)->update(['meta->a' => 5, 'meta->b->c' => 6, 'meta->f' => 2.5, 'meta->on' => false, 'views' => 9]);

        $meta = json_decode($table()->where('id', 1)->value('meta'), true);

        expect($meta)->toBe(['a' => 5, 'b' => ['c' => 6], 'f' => 2.5, 'on' => 0])
            ->and((int) $table()->where('id', 1)->value('views'))->toBe(9);
    });
});

it('fails loudly on what the server cannot do with json', function () {
    withJsonTable(function (Closure $table) {
        $table()->insert(['id' => 1, 'meta' => '{"a":1,"l":[1]}', 'views' => 1]);

        expect(fn () => $table()->where('id', 1)->update(['meta->a' => 'text']))->toThrow(QueryException::class)
            ->and(fn () => $table()->where('id', 1)->update(['meta->new' => 1]))->toThrow(QueryException::class)
            ->and(fn () => $table()->whereJsonContains('meta->l', 1)->get())->toThrow(QueryException::class)
            ->and(fn () => $table()->whereJsonLength('meta->l', 1)->get())->toThrow(QueryException::class);
    });
});

it('leaves the query as it was after running knn() by uuid', function () {
    withUuidTable(function () {
        [$source] = seedUuidNeighbours();

        $query = DB::connection('manticore')->table('it_uuid_articles_rt')->knn('embedding', $source, k: 10);

        $sql = $query->toSql();
        $wheres = $query->wheres;

        $first = $query->get();
        $query->cursor()->all();

        expect($query->toSql())->toBe($sql)
            ->and($query->wheres)->toBe($wheres)
            ->and($query->getBindings())->toBe([])
            ->and($query->get()->pluck('id')->all())->toBe($first->pluck('id')->all());
    });
});

it('reads the vector again each time knn() by uuid runs, so it sees a change', function () {
    withUuidTable(function () {
        [$source] = seedUuidNeighbours();

        $connection = DB::connection('manticore');
        $query = $connection->table('it_uuid_articles_rt')->knn('embedding', $source, k: 10);

        $connection->flushQueryLog();
        $connection->enableQueryLog();

        $query->get();
        $query->get();

        $reads = array_filter($connection->getQueryLog(), fn ($q) => str_contains($q['query'], 'limit 1'));

        expect($reads)->toHaveCount(2);
    });
});

it('partially replaces a uuid document through a table prefix', function () {
    withUuidTable(function () {
        config(['database.connections.manticore_prefixed' => ['prefix' => 'it_uuid_'] + config('database.connections.manticore')]);

        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $table = fn () => DB::connection('manticore_prefixed')->table('articles_rt');

        $table()->insert(['id' => $uuid, 'title' => 'old']);
        $table()->replaceWhereId($uuid, ['title' => 'new']);

        expect($table()->where('id', $uuid)->value('title'))->toBe('new');
    });
});

it('keeps empty values through the partial replace of a uuid document', function () {
    withUuidTable(function () {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $table = fn () => DB::connection('manticore')->table('it_uuid_articles_rt');

        $table()->insert(['id' => $uuid, 'title' => 'old']);
        $table()->replaceWhereId($uuid, ['title' => 'new']);

        expect($table()->where('id', $uuid)->first()->title)->toBe('new');
    });
});

it('refuses invalid json, which INSERT would store as nothing', function () {
    withJsonTable(function (Closure $table) {
        expect(fn () => $table()->insert(['id' => 1, 'meta' => 'not json']))
            ->toThrow(InvalidArgumentException::class, 'Column [meta]')
            ->and(fn () => $table()->where('id', 1)->update(['meta' => 'not json']))
            ->toThrow(InvalidArgumentException::class, 'Column [meta]');

        $table()->insert(['id' => 2, 'meta' => '{"a":1}']);
        $table()->insert(['id' => 3, 'meta' => '']);

        expect($table()->count())->toBe(2);
    });
});

it('takes the JSON literal null, which decodes to nothing without being invalid', function () {
    withJsonTable(function (Closure $table) {
        $table()->insert(['id' => 1, 'meta' => 'null']);

        expect($table()->count())->toBe(1);
    });
});
