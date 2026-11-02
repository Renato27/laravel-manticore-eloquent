<?php

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ManticoreEloquent\Eloquent\ManticoreModel;

/**
 * Manticore has no NULL outside JSON: a column without a value holds 0 or an empty string.
 */

class NullablePost extends ManticoreModel
{
    protected $table = 'null_posts';
    protected $guarded = [];
    public $timestamps = false;
    protected $casts = ['seen_at' => 'datetime', 'meta' => 'array'];
}

class SoftPost extends ManticoreModel
{
    use SoftDeletes;

    protected $table = 'null_posts';
    protected $guarded = [];
    public $timestamps = false;
}

function nullTable(): \Illuminate\Database\Query\Builder
{
    return DB::connection('manticore')->table('null_posts');
}

beforeEach(function () {
    try {
        DB::connection('manticore')->getPdo();
    } catch (\Throwable $e) {
        $this->markTestSkipped('Manticore not reachable on the MySQL port: ' . $e->getMessage());
    }

    $schema = Schema::connection('manticore');
    $schema->dropIfExists('null_posts');
    $schema->create('null_posts', function (Blueprint $table) {
        $table->text('title');
        $table->integer('views');
        $table->float('score');
        $table->string('slug');
        $table->boolean('flag');
        $table->timestamp('seen_at');
        $table->timestamp('deleted_at');
        $table->json('meta');
    });

    nullTable()->insert(['id' => 1, 'title' => 'with values', 'views' => 5, 'score' => 1.5, 'slug' => 'a', 'flag' => 1, 'seen_at' => 1000, 'meta' => '{"a":1}']);
    nullTable()->insert(['id' => 2, 'title' => 'empty']);
});

afterEach(function () {
    try {
        Schema::connection('manticore')->dropIfExists('null_posts');
    } catch (\Throwable $e) {
        // Nothing to clean up when the server is not reachable.
    }
});

it('treats the empty value of each type as null', function (string $column) {
    expect(nullTable()->whereNull($column)->pluck('id')->all())->toBe([2])
        ->and(nullTable()->whereNotNull($column)->pluck('id')->all())->toBe([1])
        ->and(nullTable()->where($column, null)->pluck('id')->all())->toBe([2])
        ->and(nullTable()->whereNotNull('null_posts.' . $column)->pluck('id')->all())->toBe([1]);
})->with(['views', 'score', 'slug', 'flag', 'seen_at', 'meta']);

it('counts a real 0 as null, since the server cannot tell them apart', function () {
    nullTable()->insert(['id' => 3, 'title' => 'zero', 'views' => 0]);

    expect(nullTable()->whereNull('views')->orderBy('id')->pluck('id')->all())->toBe([2, 3]);
});

it('fails loudly on a null filter over a text field', function () {
    nullTable()->whereNull('title')->get();
})->throws(QueryException::class, 'title');

it('keeps the or of a null filter', function () {
    expect(nullTable()->where('id', 1)->orWhereNull('views')->count())->toBe(2)
        ->and(nullTable()->where('id', 1)->whereNull('views')->count())->toBe(0);
});

it('writes null as the empty value of the column', function () {
    nullTable()->where('id', 1)->update(['views' => null, 'score' => null, 'slug' => null, 'flag' => null, 'seen_at' => null, 'meta' => null]);
    nullTable()->replace(['id' => 2, 'title' => null, 'views' => null, 'slug' => null, 'meta' => null]);
    NullablePost::create(['id' => 3, 'title' => null, 'views' => null, 'slug' => null, 'seen_at' => null, 'meta' => null]);

    expect(nullTable()->whereNull('views')->count())->toBe(3)
        ->and(nullTable()->whereNull('slug')->count())->toBe(3)
        ->and(nullTable()->whereNull('seen_at')->count())->toBe(3)
        ->and(nullTable()->whereNull('meta')->count())->toBe(3);
});

it('reads a date without a value as null on the model', function () {
    $empty = NullablePost::find(2);
    $full = NullablePost::find(1);

    expect($empty->seen_at)->toBeNull()
        ->and($empty->toArray()['seen_at'])->toBeNull()
        ->and($full->seen_at)->toBeInstanceOf(Carbon::class)
        ->and($full->seen_at->getTimestamp())->toBe(1000);
});

it('supports SoftDeletes end to end', function () {
    $post = SoftPost::find(1);

    expect($post->trashed())->toBeFalse();

    $post->delete();

    expect($post->trashed())->toBeTrue()
        ->and(SoftPost::find(1))->toBeNull()
        ->and(SoftPost::pluck('id')->all())->toBe([2])
        ->and(SoftPost::withTrashed()->count())->toBe(2)
        ->and(SoftPost::onlyTrashed()->pluck('id')->all())->toBe([1])
        ->and(SoftPost::withTrashed()->find(1)->trashed())->toBeTrue();

    SoftPost::withTrashed()->find(1)->restore();

    expect(SoftPost::find(1)->trashed())->toBeFalse()
        ->and(SoftPost::count())->toBe(2)
        ->and(SoftPost::onlyTrashed()->count())->toBe(0);

    SoftPost::find(1)->forceDelete();

    expect(SoftPost::withTrashed()->find(1))->toBeNull();
});
