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

    public function firstNoteComment()
    {
        return $this->hasOneThrough(RelComment::class, RelNote::class, 'post_id', 'commentable_id');
    }

    public function taggedWithPivot()
    {
        return $this->belongsToMany(RelTag::class, 'rel_post_tag', 'post_id', 'tag_id')->withPivot('weight');
    }

    public function morphTags()
    {
        return $this->morphToMany(RelTag::class, 'taggable', 'rel_taggables', 'taggable_id', 'tag_id');
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
    return ['rel_posts', 'rel_notes', 'rel_comments', 'rel_tags', 'rel_post_tag', 'rel_taggables', 'rel_uuid_posts', 'rel_uuid_notes'];
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
        $table->integer('weight');
    });
    $schema->create('rel_taggables', function (Blueprint $table) {
        $table->integer('taggable_id');
        $table->string('taggable_type');
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

it('limit: whereHas() and withCount() need a subquery in a place the server does not take one', function (Closure $query) {
    expect($query)->toThrow(QueryException::class);
})->with([
    'whereHas' => [fn () => RelPost::whereHas('notes')->get()],
    'withCount' => [fn () => RelPost::withCount('notes')->get()],
]);

it('limit: match() on a relation collides with the one Eloquent has', function () {
    expect(fn () => RelPost::find(1)->notes()->match('another'))->toThrow(TypeError::class);
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

function seedTags(): void
{
    foreach ([1 => 'php', 2 => 'search', 3 => 'unused'] as $id => $name) {
        RelTag::create(['id' => $id, 'name' => $name]);
    }

    DB::connection('manticore')->table('rel_post_tag')->insert([
        ['post_id' => 1, 'tag_id' => 1, 'weight' => 5],
        ['post_id' => 1, 'tag_id' => 2, 'weight' => 9],
        ['post_id' => 2, 'tag_id' => 2, 'weight' => 1],
    ]);
}

it('loads belongsToMany on demand and eagerly', function () {
    seedTags();

    $tags = RelPost::find(1)->tags->sortBy('id')->values();

    expect($tags->modelKeys())->toBe([1, 2])
        ->and($tags->first())->toBeInstanceOf(RelTag::class)
        ->and($tags->first()->name)->toBe('php')
        ->and((int) $tags->first()->pivot->post_id)->toBe(1)
        ->and((int) $tags->first()->pivot->tag_id)->toBe(1);

    $posts = RelPost::with('tags')->orderBy('id')->get();

    expect($posts[0]->tags->modelKeys())->toEqualCanonicalizing([1, 2])
        ->and($posts[1]->tags->modelKeys())->toBe([2]);
});

it('keeps the pivot table out of a related model', function () {
    seedTags();

    $tag = RelPost::find(1)->tags->sortBy('id')->first();

    expect(array_keys($tag->getAttributes()))->toEqualCanonicalizing(['id', 'name'])
        ->and(array_keys($tag->toArray()))->toEqualCanonicalizing(['id', 'name', 'pivot']);
});

it('filters, orders, counts and pages belongsToMany', function () {
    seedTags();

    $relation = fn () => RelPost::find(1)->taggedWithPivot();

    expect($relation()->orderBy('rel_tags.id', 'desc')->pluck('rel_tags.id')->all())->toBe([2, 1])
        ->and((int) $relation()->orderByPivot('weight', 'desc')->first()->pivot->weight)->toBe(9)
        ->and($relation()->wherePivot('weight', '>', 6)->get()->modelKeys())->toBe([2])
        ->and($relation()->wherePivot('weight', '9')->get()->modelKeys())->toBe([2])
        ->and($relation()->wherePivotIn('tag_id', [1])->get()->modelKeys())->toBe([1])
        ->and($relation()->count())->toBe(2)
        ->and($relation()->exists())->toBeTrue()
        ->and($relation()->where('rel_tags.id', 2)->first()->id)->toBe(2)
        ->and($relation()->paginate(1)->total())->toBe(2)
        ->and(iterator_count($relation()->cursor()))->toBe(2)
        ->and($relation()->cursor()->first()->pivot->post_id)->not->toBeNull();
});

it('writes through belongsToMany', function () {
    seedTags();

    $post = RelPost::find(2);

    $post->tags()->attach(1, ['weight' => 3]);
    expect($post->tags()->pluck('rel_tags.id')->sort()->values()->all())->toBe([1, 2]);

    $post->tags()->detach(2);
    expect($post->tags()->pluck('rel_tags.id')->all())->toBe([1]);

    $post->tags()->sync([2, 3]);
    expect($post->tags()->pluck('rel_tags.id')->sort()->values()->all())->toBe([2, 3]);

    $post->tags()->updateExistingPivot(2, ['weight' => 8]);
    expect((int) $post->taggedWithPivot()->where('rel_tags.id', 2)->first()->pivot->weight)->toBe(8);

    $post->tags()->detach();
    expect($post->tags()->count())->toBe(0);
});

it('loads morphToMany', function () {
    RelTag::create(['id' => 1, 'name' => 'php']);
    RelPost::find(1)->morphTags()->attach(1);

    expect(RelPost::find(1)->morphTags->modelKeys())->toBe([1])
        ->and(RelPost::find(2)->morphTags)->toHaveCount(0);
});

it('loads hasManyThrough and hasOneThrough', function () {
    RelComment::create(['id' => 100, 'body' => 'on a note', 'commentable_type' => 'x', 'commentable_id' => 10]);
    RelComment::create(['id' => 101, 'body' => 'on another', 'commentable_type' => 'x', 'commentable_id' => 11]);
    RelComment::create(['id' => 102, 'body' => 'elsewhere', 'commentable_type' => 'x', 'commentable_id' => 12]);

    $comments = RelPost::find(1)->noteComments;

    expect($comments->modelKeys())->toEqualCanonicalizing([100, 101])
        ->and($comments->first())->toBeInstanceOf(RelComment::class)
        ->and(array_keys($comments->first()->getAttributes()))->toEqualCanonicalizing(['id', 'body', 'commentable_type', 'commentable_id', 'laravel_through_key']);

    expect(RelPost::with('noteComments')->find(2)->noteComments->modelKeys())->toBe([102])
        ->and(RelPost::find(2)->firstNoteComment->id)->toBe(102)
        ->and(RelPost::find(1)->noteComments()->count())->toBe(2);
});
