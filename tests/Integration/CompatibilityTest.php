<?php

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ManticoreEloquent\Eloquent\ManticoreModel;

/**
 * The "works as usual" list of docs/compatibility.md, run against a real server. The translated and
 * failing parts have their own tests.
 */
class CompatibleDocument extends ManticoreModel
{
    protected $table = 'compat_docs';

    protected $guarded = [];

    public $timestamps = false;

    protected $appends = ['label'];

    protected $casts = ['published' => 'boolean'];

    public static array $saved = [];

    protected static function booted(): void
    {
        static::saved(function ($document) {
            static::$saved[] = $document->id;
        });
    }

    public function scopePublished($query)
    {
        return $query->where('published', true);
    }

    protected function label(): Attribute
    {
        return Attribute::get(fn () => strtoupper($this->slug));
    }
}

function compatTable(): Builder
{
    return DB::connection('manticore')->table('compat_docs');
}

beforeEach(function () {
    try {
        DB::connection('manticore')->getPdo();
    } catch (Throwable $e) {
        $this->markTestSkipped('Manticore not reachable on the MySQL port: '.$e->getMessage());
    }

    Schema::connection('manticore')->dropIfExists('compat_docs');
    Schema::connection('manticore')->create('compat_docs', function (Blueprint $table) {
        $table->text('title');
        $table->integer('views');
        $table->string('slug');
        $table->boolean('published');
    });

    CompatibleDocument::$saved = [];

    foreach ([1 => 10, 2 => 20, 3 => 30] as $id => $views) {
        CompatibleDocument::create(['id' => $id, 'title' => "doc {$id}", 'views' => $views, 'slug' => "s{$id}", 'published' => $id < 3]);
    }
});

afterEach(function () {
    try {
        Schema::connection('manticore')->dropIfExists('compat_docs');
    } catch (Throwable $e) {
        // Nothing to clean up when the server is not reachable.
    }
});

it('reads', function () {
    expect(CompatibleDocument::find(1)->slug)->toBe('s1')
        ->and(CompatibleDocument::orderBy('id')->first()->id)->toBe(1)
        ->and(CompatibleDocument::orderBy('id')->get()->modelKeys())->toBe([1, 2, 3])
        ->and(CompatibleDocument::all())->toHaveCount(3)
        ->and(CompatibleDocument::orderBy('id')->pluck('slug', 'id')->all())->toBe([1 => 's1', 2 => 's2', 3 => 's3'])
        ->and(CompatibleDocument::where('id', 2)->value('slug'))->toBe('s2')
        ->and(CompatibleDocument::where('id', 2)->sole()->id)->toBe(2)
        ->and(CompatibleDocument::where('id', 2)->exists())->toBeTrue()
        ->and(CompatibleDocument::where('id', 9)->doesntExist())->toBeTrue();
});

it('aggregates', function () {
    expect(CompatibleDocument::count())->toBe(3)
        ->and((int) CompatibleDocument::sum('views'))->toBe(60)
        ->and((int) CompatibleDocument::avg('views'))->toBe(20)
        ->and((int) CompatibleDocument::min('views'))->toBe(10)
        ->and((int) CompatibleDocument::max('views'))->toBe(30);
});

it('pages and streams', function () {
    $seen = [];
    CompatibleDocument::orderBy('id')->chunk(2, function ($rows) use (&$seen) {
        $seen = array_merge($seen, $rows->modelKeys());
    });

    $byId = [];
    CompatibleDocument::query()->chunkById(2, function ($rows) use (&$byId) {
        $byId = array_merge($byId, $rows->modelKeys());
    });

    expect($seen)->toBe([1, 2, 3])
        ->and($byId)->toBe([1, 2, 3])
        ->and(CompatibleDocument::paginate(2)->total())->toBe(3)
        ->and(CompatibleDocument::orderBy('id')->simplePaginate(2)->hasMorePages())->toBeTrue()
        ->and(CompatibleDocument::orderBy('id')->cursorPaginate(2)->hasMorePages())->toBeTrue()
        ->and(CompatibleDocument::orderBy('id')->lazy(2)->pluck('id')->all())->toBe([1, 2, 3])
        ->and(CompatibleDocument::orderBy('id')->cursor()->pluck('id')->all())->toBe([1, 2, 3]);
});

it('filters and orders', function () {
    expect(CompatibleDocument::where('views', '>', 10)->where('views', '<', 30)->pluck('id')->all())->toBe([2])
        ->and(CompatibleDocument::where('id', 1)->orWhere('id', 2)->count())->toBe(2)
        ->and(CompatibleDocument::whereIn('id', [1, 2])->count())->toBe(2)
        ->and(CompatibleDocument::whereNotIn('id', [1, 2])->pluck('id')->all())->toBe([3])
        ->and(CompatibleDocument::whereBetween('views', [15, 35])->count())->toBe(2)
        ->and(CompatibleDocument::whereKey(1)->first()->id)->toBe(1)
        ->and(CompatibleDocument::where(fn ($q) => $q->where('id', 1)->orWhere('id', 3))->where('published', true)->pluck('id')->all())->toBe([1])
        ->and(CompatibleDocument::orderBy('views', 'desc')->pluck('id')->all())->toBe([3, 2, 1])
        ->and(CompatibleDocument::latest('views')->first()->id)->toBe(3)
        ->and(CompatibleDocument::orderBy('id')->offset(1)->limit(1)->pluck('id')->all())->toBe([2]);
});

it('groups', function () {
    $groups = compatTable()->selectRaw('published, count(*) as total')->groupBy('published')->having('total', '>', 1)->get();

    expect($groups)->toHaveCount(1)
        ->and((int) $groups->first()->total)->toBe(2)
        ->and(compatTable()->select('slug')->where('id', 1)->first()->slug)->toBe('s1');
});

it('writes', function () {
    expect(compatTable()->insertGetId(['id' => 4, 'title' => 'x', 'views' => 1, 'slug' => 'x', 'published' => 0]))->toBe(4);

    $document = CompatibleDocument::find(1);
    $document->views = 11;
    $document->save();

    expect(CompatibleDocument::find(1)->views)->toBe(11)
        ->and(CompatibleDocument::destroy(4))->toBe(1)
        ->and(CompatibleDocument::find(4))->toBeNull();

    CompatibleDocument::where('id', 1)->update(['views' => 12]);

    expect($document->fresh()->views)->toBe(12)
        ->and(CompatibleDocument::firstOrCreate(['id' => 5], ['title' => 'y', 'views' => 2, 'slug' => 'y', 'published' => 1])->wasRecentlyCreated)->toBeTrue()
        ->and(CompatibleDocument::updateOrCreate(['id' => 5], ['views' => 3])->views)->toBe(3);
});

it('keeps what a model adds', function () {
    expect(CompatibleDocument::published()->count())->toBe(2)
        ->and(CompatibleDocument::find(1)->published)->toBeTrue()
        ->and(CompatibleDocument::find(1)->label)->toBe('S1')
        ->and(CompatibleDocument::find(1)->toArray())->toHaveKey('label', 'S1')
        ->and(CompatibleDocument::$saved)->toBe([1, 2, 3]);
});

it('ignores locks', function () {
    expect(compatTable()->where('id', 1)->lockForUpdate()->count())->toBe(1)
        ->and(compatTable()->where('id', 1)->sharedLock()->first()->id)->toBe(1);
});
