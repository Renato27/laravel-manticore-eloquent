<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ManticoreEloquent\Eloquent\ManticoreModel;

/**
 * Relations against a real Manticore, on both sides of a relational connection.
 */
class RelPost extends ManticoreModel
{
    protected $table = 'rel_posts';

    protected $guarded = [];

    public $timestamps = false;

    public function notes()
    {
        return $this->hasMany(RelNote::class, 'post_id');
    }

    public function firstNote()
    {
        return $this->hasOne(RelNote::class, 'post_id');
    }

    public function comments()
    {
        return $this->morphMany(RelComment::class, 'commentable');
    }

    public function latestComment()
    {
        return $this->morphOne(RelComment::class, 'commentable');
    }

    public function author()
    {
        return $this->belongsTo(RelAuthor::class, 'author_id');
    }

    public function reviews()
    {
        return $this->hasMany(RelReview::class, 'post_id');
    }

    public function tags()
    {
        return $this->belongsToMany(RelTag::class, 'rel_post_tag', 'post_id', 'tag_id');
    }

    public function noteComments()
    {
        return $this->hasManyThrough(RelComment::class, RelNote::class, 'post_id', 'commentable_id');
    }
}

class RelNote extends ManticoreModel
{
    protected $table = 'rel_notes';

    protected $guarded = [];

    public $timestamps = false;

    public function post()
    {
        return $this->belongsTo(RelPost::class, 'post_id');
    }
}

class RelComment extends ManticoreModel
{
    protected $table = 'rel_comments';

    protected $guarded = [];

    public $timestamps = false;

    public function commentable()
    {
        return $this->morphTo();
    }
}

class RelTag extends ManticoreModel
{
    protected $table = 'rel_tags';

    protected $guarded = [];

    public $timestamps = false;
}

class RelAuthor extends Model
{
    protected $connection = 'relational';

    protected $table = 'rel_authors';

    protected $guarded = [];

    public $timestamps = false;

    public function posts()
    {
        return $this->hasMany(RelPost::class, 'author_id');
    }
}

class RelReview extends Model
{
    protected $connection = 'relational';

    protected $table = 'rel_reviews';

    protected $guarded = [];

    public $timestamps = false;
}

class RelUuidPost extends ManticoreModel
{
    protected $table = 'rel_uuid_posts';

    protected $guarded = [];

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    public function notes()
    {
        return $this->hasMany(RelUuidNote::class, 'post_uuid');
    }
}

class RelUuidNote extends ManticoreModel
{
    protected $table = 'rel_uuid_notes';

    protected $guarded = [];

    public $timestamps = false;

    public function post()
    {
        return $this->belongsTo(RelUuidPost::class, 'post_uuid');
    }
}

function relationTables(): array
{
    return ['rel_posts', 'rel_notes', 'rel_comments', 'rel_tags', 'rel_post_tag', 'rel_uuid_posts', 'rel_uuid_notes'];
}

beforeEach(function () {
    try {
        DB::connection('manticore')->getPdo();
    } catch (Throwable $e) {
        $this->markTestSkipped('Manticore not reachable on the MySQL port: '.$e->getMessage());
    }

    $schema = Schema::connection('manticore');

    foreach (relationTables() as $table) {
        $schema->dropIfExists($table);
    }

    $schema->create('rel_posts', function (Blueprint $table) {
        $table->text('title');
        $table->integer('author_id');
    });
    $schema->create('rel_notes', function (Blueprint $table) {
        $table->text('body');
        $table->integer('post_id');
    });
    $schema->create('rel_comments', function (Blueprint $table) {
        $table->text('body');
        $table->string('commentable_type');
        $table->integer('commentable_id');
    });
    $schema->create('rel_tags', function (Blueprint $table) {
        $table->text('name');
    });
    $schema->create('rel_post_tag', function (Blueprint $table) {
        $table->integer('post_id');
        $table->integer('tag_id');
    });
    $schema->create('rel_uuid_posts', function (Blueprint $table) {
        $table->uuid('id');
        $table->text('title');
    });
    $schema->create('rel_uuid_notes', function (Blueprint $table) {
        $table->text('body');
        $table->string('post_uuid');
    });

    config(['database.connections.relational' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);

    Schema::connection('relational')->create('rel_authors', function (Blueprint $table) {
        $table->id();
        $table->string('name');
    });
    Schema::connection('relational')->create('rel_reviews', function (Blueprint $table) {
        $table->id();
        $table->integer('post_id');
    });

    RelPost::create(['id' => 1, 'title' => 'first', 'author_id' => 7]);
    RelPost::create(['id' => 2, 'title' => 'second', 'author_id' => 7]);
    RelNote::create(['id' => 10, 'body' => 'a note', 'post_id' => 1]);
    RelNote::create(['id' => 11, 'body' => 'another note', 'post_id' => 1]);
    RelNote::create(['id' => 12, 'body' => 'elsewhere', 'post_id' => 2]);
    RelAuthor::create(['id' => 7, 'name' => 'ana']);
    RelReview::create(['id' => 1, 'post_id' => 1]);
});

afterEach(function () {
    foreach (relationTables() as $table) {
        try {
            Schema::connection('manticore')->dropIfExists($table);
        } catch (Throwable) {
        }
    }

    DB::purge('relational');
});

it('loads a hasMany on demand', function () {
    expect(RelPost::find(1)->notes->modelKeys())->toEqualCanonicalizing([10, 11]);
});

it('eager loads a hasMany', function () {
    $posts = RelPost::with('notes')->orderBy('id')->get();

    expect($posts[0]->notes->modelKeys())->toEqualCanonicalizing([10, 11]);
    expect($posts[1]->notes->modelKeys())->toBe([12]);
});

it('loads a hasOne on demand and eagerly', function () {
    expect(RelPost::find(2)->firstNote->id)->toBe(12);
    expect(RelPost::with('firstNote')->find(2)->firstNote->id)->toBe(12);
});

it('loads a belongsTo on demand and eagerly', function () {
    expect(RelNote::find(10)->post->title)->toBe('first');
    expect(RelNote::with('post')->find(12)->post->title)->toBe('second');
});

it('filters, counts and writes through a relation', function () {
    $post = RelPost::find(1);

    expect($post->notes()->count())->toBe(2);
    expect($post->notes()->where('id', '!=', 11)->pluck('id')->all())->toBe([10]);
    expect($post->notes()->getQuery()->match('another')->pluck('id')->all())->toBe([11]);
    expect($post->notes()->exists())->toBeTrue();

    $note = $post->notes()->create(['id' => 13, 'body' => 'through the relation']);

    expect($note->post_id)->toBe(1);
    expect(RelNote::find(13)->post_id)->toBe(1);
});

it('loads a polymorphic relation in both directions', function () {
    RelPost::find(1)->comments()->create(['id' => 20, 'body' => 'nice']);

    expect(RelPost::find(1)->comments->modelKeys())->toBe([20]);
    expect(RelPost::with('comments')->find(1)->comments->modelKeys())->toBe([20]);
    expect(RelPost::find(1)->latestComment->id)->toBe(20);
    expect(RelPost::with('latestComment')->find(1)->latestComment->id)->toBe(20);
    expect(RelComment::find(20)->commentable->title)->toBe('first');
    expect(RelComment::with('commentable')->find(20)->commentable->title)->toBe('first');
});

it('loads relations keyed by a uuid in a string column', function () {
    $uuid = '550e8400-e29b-41d4-a716-446655440000';

    RelUuidPost::create(['id' => $uuid, 'title' => 'keyed']);
    RelUuidNote::create(['id' => 1, 'body' => 'for the uuid', 'post_uuid' => $uuid]);
    RelUuidNote::create(['id' => 2, 'body' => 'for nothing', 'post_uuid' => '660e8400-e29b-41d4-a716-446655440001']);

    expect(RelUuidPost::find($uuid)->notes->modelKeys())->toBe([1]);
    expect(RelUuidPost::with('notes')->find($uuid)->notes->modelKeys())->toBe([1]);
    expect(RelUuidNote::find(1)->post->title)->toBe('keyed');
});

it('loads Manticore models from a relational parent', function () {
    expect(RelAuthor::find(7)->posts->modelKeys())->toEqualCanonicalizing([1, 2]);
    expect(RelAuthor::with('posts')->find(7)->posts->modelKeys())->toEqualCanonicalizing([1, 2]);
});

it('still loads relational models from a Manticore parent', function () {
    expect(RelPost::with('author')->find(1)->author->name)->toBe('ana');
    expect(RelPost::find(1)->reviews->modelKeys())->toBe([1]);
});

it('limit: a relation that needs a join or a subquery fails with an error', function (Closure $query) {
    expect($query)->toThrow(QueryException::class);
})->with([
    'belongsToMany' => [fn () => RelPost::find(1)->tags],
    'hasManyThrough' => [fn () => RelPost::find(1)->noteComments],
    'whereHas' => [fn () => RelPost::whereHas('notes')->get()],
    'withCount' => [fn () => RelPost::withCount('notes')->get()],
]);

it('limit: match() on a relation collides with the one Eloquent has', function () {
    expect(fn () => RelPost::find(1)->notes()->match('another'))->toThrow(TypeError::class);
});

it('limit: join() from the query builder fails with an error', function () {
    expect(fn () => DB::connection('manticore')->table('rel_notes')->join('rel_posts', 'rel_notes.post_id', '=', 'rel_posts.id')->get())
        ->toThrow(QueryException::class);
});

it('limit: a qualified column in orderBy() fails with an error', function () {
    expect(fn () => RelNote::query()->orderBy('rel_notes.id')->get())->toThrow(QueryException::class);
});

it('runs a join written by hand without backticks', function () {
    $rows = DB::connection('manticore')->select('SELECT * FROM rel_notes INNER JOIN rel_posts ON rel_notes.post_id = rel_posts.id');

    expect($rows)->toHaveCount(3);
});

it('keeps a whereNotNull() written by hand on a json column', function () {
    $schema = Schema::connection('manticore');
    $schema->dropIfExists('rel_json');
    $schema->create('rel_json', function (Blueprint $table) {
        $table->text('title');
        $table->json('meta');
    });

    try {
        DB::connection('manticore')->table('rel_json')->insert(['id' => 1, 'title' => 'with', 'meta' => '{"a":1}']);
        DB::connection('manticore')->table('rel_json')->insert(['id' => 2, 'title' => 'without']);

        expect(DB::connection('manticore')->table('rel_json')->whereNotNull('meta')->pluck('id')->all())->toBe([1]);
        expect(DB::connection('manticore')->table('rel_json')->whereNotNull('rel_json.meta')->pluck('id')->all())->toBe([1]);
        expect(DB::connection('manticore')->table('rel_json')->where('id', 2)->orWhereNotNull('rel_json.meta')->count())->toBe(2);
    } finally {
        $schema->dropIfExists('rel_json');
    }
});
