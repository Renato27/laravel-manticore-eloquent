# Laravel Manticore Eloquent

Use **Eloquent natively on Manticore Search**. This package registers Manticore as a
first-class Laravel database connection over the **MySQL protocol** (port `9306`), so the
*entire* Eloquent / Query Builder API works unchanged — `where`, `find`, `create`,
`update`, `delete`, `paginate`, `chunk`, scopes, casts, relations, migrations — plus
Manticore helpers (`match`, `knn`, `highlight`, `option`, `maxMatches`, `facet`, `replace`).
To keep a relational database in sync with Manticore automatically, see the companion
package `renatomaldonado/laravel-manticore-sync`.

> This is a separate, ground-up library. If you use the older
> `renatomaldonado/manticore-laravel-search` (HTTP/JSON fluent builder + consolidation),
> keep using it for those features — this package does not replace it, it sits beside it.

## Why a database driver?

"Make Eloquent work, just pointing at Manticore instead of the DB" is, by definition, a
Laravel **database driver**. Manticore speaks the MySQL wire protocol, and all of Eloquent
is built on a `Connection` + `Grammar`. So instead of re-implementing Eloquent method by
method, we implement a thin Manticore grammar and inherit the whole framework.

## Requirements

| Dependency | Version |
|---|---|
| PHP | `^8.1` (with `pdo_mysql`) |
| Laravel | `^10.0 \| ^11.0 \| ^12.0 \| ^13.0` |
| Manticore Search | MySQL protocol enabled (default port `9306`) |

## Installation

```bash
composer require renatomaldonado/laravel-manticore-eloquent
```

```bash
php artisan vendor:publish --provider="ManticoreEloquent\ManticoreEloquentServiceProvider" --tag=config
```

Configure `config/manticore-eloquent.php` (or the matching env vars):

```php
'connection' => env('MANTICORE_CONNECTION', 'manticore'),
'host'       => env('MANTICORE_HOST', '127.0.0.1'),
'port'       => env('MANTICORE_PORT', 9306),   // MySQL protocol port
'username'   => env('MANTICORE_USERNAME'),
'password'   => env('MANTICORE_PASSWORD'),
'engine'     => env('MANTICORE_ENGINE'),       // 'columnar' | 'rowwise' | null
```

The package auto-registers a `manticore` connection in `config/database.connections`
derived from this file, so you don't have to edit `config/database.php`.

## Usage

### Option A — a model that lives entirely in Manticore

Extend `ManticoreModel`; its default connection is Manticore.

```php
use ManticoreEloquent\Eloquent\ManticoreModel;

class Article extends ManticoreModel
{
    protected $table = 'articles_rt';
    protected $guarded = [];
    protected $casts = ['published' => 'boolean', 'created_at' => 'datetime'];
}

Article::where('published', true)->match('laravel')->orderBy('created_at', 'desc')->paginate();
Article::find(10);
Article::create(['title' => 'Hello', 'body' => '…', 'published' => true]);
Article::where('id', 10)->update(['published' => false]);
Article::destroy(10);
```

### Option B — a relational model that can also be queried via Manticore

Add the `HasManticore` trait; the model keeps its normal DB connection, and
`::manticore()` opens an Eloquent builder on Manticore.

```php
use Illuminate\Database\Eloquent\Model;
use ManticoreEloquent\Concerns\HasManticore;

class Company extends Model
{
    use HasManticore;

    public function searchableAs(): string
    {
        return 'companies_rt'; // Manticore index name
    }
}

Company::query()->where('id', 1)->first();          // relational DB
Company::manticore()->match('fintech')->paginate(); // Manticore
```

### Manticore-specific helpers

```php
Article::manticore()
    ->match('cloud native', 'title')   // MATCH('@title cloud native')
    ->where('views', '>', 100)
    ->option('ranker', 'sph04')        // OPTION ranker=sph04
    ->maxMatches(5000)                 // OPTION max_matches=5000
    ->facet('category')                // FACET category
    ->get();

// Full-document write (REPLACE INTO) — needed to update full-text fields
Article::manticore()->replace(['id' => 1, 'title' => 'New', 'body' => '…']);
```

Option names and values are never bound — Manticore's `OPTION` and `knn()` blocks take bare
keywords, not quoted strings. There is no list of allowed options (the server keeps adding
them), so instead both sides are checked for shape: a name must be a plain identifier, and a
value must be a number, a bool, or a bare word. Anything richer is explicit:

```php
->option('ranker', DB::raw("expr('sum(lcs*user_weight)')"))  // passed through verbatim
->option('ranker', $request->input('ranker'))                // throws unless it's a bare word
```

`max_matches` is raised automatically when you page beyond Manticore's default cap of
1000 rows, so deep `paginate()`/`offset()` just works.

#### Partial replace

`replaceWhereId()` rewrites only the fields you pass and keeps the rest of the document,
including full-text fields that `update()` cannot change:

```php
DB::connection('manticore')->table('articles')->replaceWhereId(1, ['tag' => 'beta']);
// replace into `articles` set `tag` = ? where `id` = ?
```

Constraints that come from Manticore, not from this driver:

- It needs **Manticore Buddy**. It ships inside the `manticore` package from 25.0.0 on;
  before that it was the separate `manticore-extra` package.
- It filters by `id` only, so it writes one document per call.
- Every field of the table must be `stored` (the default).
- **On an id that does not exist it creates a document holding only the fields you
  passed**, with defaults everywhere else, and it does not report that. Check that the
  document exists first if that matters.
- **With a [UUID id](#uuid-document-ids) it is not atomic.** Buddy only handles numeric ids,
  so when Manticore rejects the statement the driver reads the document, overlays your fields
  and rewrites all of it. A write to that document in between is lost, and vector values go
  through their text form, which keeps about 8 significant digits.

#### UUID document ids

Manticore can use a UUID as the document id, so a document can keep the primary key it has
in your main database. This needs Manticore 28.6.6 or later and a real-time table, and the
choice is made when the table is created: a numeric table cannot be converted with `ALTER`.

Declare the id in the migration. It is the only column that is ever declared, and `uuid` is
only allowed on `id`:

```php
Schema::connection('manticore')->create('articles', function (Blueprint $table) {
    $table->uuid('id');
    $table->text('title');
    $table->integer('views');
});
// create table `articles` (`id` uuid, `title` text, `views` integer)
```

Then tell the model its key is a string, or use Laravel's `HasUuids` trait, which does that
and also generates the id:

```php
class Article extends ManticoreModel
{
    public $incrementing = false;
    protected $keyType = 'string';
}

$article = Article::create(['id' => '550e8400-e29b-41d4-a716-446655440000', 'title' => 'Hello', 'views' => 1]);
Article::find('550e8400-e29b-41d4-a716-446655440000');
Article::where('id', '550e8400-e29b-41d4-a716-446655440000')->first();
$article->update(['views' => 2]);
$article->delete();
```

What differs from integer ids:

- **Give the id yourself, or use `HasUuids`.** Manticore can generate a UUID when you leave
  the id out, but the MySQL protocol cannot return it through `lastInsertId()`, which reports
  `0`. The model never learns its key; only `SELECT LAST_INSERT_ID()` has it.
- **The id must be one Manticore accepts:** versions 1 to 8, variant `8`, `9`, `a` or `b`.
  The nil UUID is refused. Any case works, and it always reads back in lowercase.
- **No range or null filters on `id`,** so `chunkById()` and `lazyById()` fail; use `chunk()`.
  `=`, `!=` and `IN` work, and so does `orderBy('id')`.
- **`replaceWhereId()` and `knn()` take a UUID, with two extra costs.** Manticore only does
  these by numeric id, so the driver does the work: `replaceWhereId()` has the limits listed
  under [Partial replace](#partial-replace), and `knn()` runs one more query to read the
  document's vector. A document that does not exist or has no vector gives no results.
- **`toSql()` shows `knn()` with the UUID until the query runs,** and with the vector after.
  Don't send that SQL by hand, and never quote the UUID: Manticore reads a *quoted* string
  there as text to embed, which on an auto-embeddings column returns unrelated documents and
  reports no problem.
- **Before Manticore 29.2.5, naming a text column in the select returns it empty** on a UUID
  table, as in `select('id', 'title')`. `select *`, the default, is not affected.

#### KNN / vector search

Requires the column to be declared as a `floatVector` in the migration (see below).

```php
// knn(`embedding`, 10, (0.12, 0.98, …))  — pair with knn_dist() to read the score
Article::manticore()
    ->knn('embedding', $queryVector, k: 10)   // optional ef: ->knn(..., ef: 2000)
    ->selectRaw('*, knn_dist() as distance')
    ->get();

// "more like this document": pass a document id instead of a vector. Manticore returns
// the neighbours, never the source document itself. A UUID works too.
Article::manticore()->knn('embedding', $article->id, k: 10)->get();

// The 4th argument is the whole option block — knn(…, {ef=2000, rescore=1}). Any option
// Manticore accepts there goes through; float options need a float (3.0, not 3).
Article::manticore()
    ->knn('embedding', $queryVector, k: 10, ef: ['ef' => 2000, 'rescore' => true])
    ->get();
```

Writing vectors needs the `VectorCast`, because Manticore only accepts a vector as a bare
tuple literal — `(0.1, 0.2)` — which no bound parameter can express:

```php
use ManticoreEloquent\Eloquent\Casts\VectorCast;

class Article extends ManticoreModel
{
    protected $casts = ['embedding' => VectorCast::class];
}

Article::create(['embedding' => [0.1, 0.2, 0.3]]);  // → values ((0.1, 0.2, 0.3))
Article::query()->where('id', 1)->first()->embedding; // → [0.1, 0.2, 0.3]
```

Two things to know about vectors once they are stored: a `cosine` column normalizes them,
so the values you read back are the unit vector, not what you wrote (`L2` round-trips
exactly); and Manticore refuses `UPDATE` on a KNN-indexed column, so changing a vector
means rewriting the row with `replace()`:

```php
DB::connection('manticore')->table('articles')
    ->replace([(new Article(['id' => 1, 'embedding' => [0.4, 0.5, 0.6]]))->getAttributes()]);
```

#### Highlighting

```php
Article::manticore()
    ->match('cloud native')
    ->highlight('body', ['before_match' => '<mark>', 'after_match' => '</mark>'])
    ->get();   // each row gets a `highlight` column (alias configurable)
```

## Migrations

Manticore real-time indexes are created with standard Laravel migrations. The schema
grammar maps Laravel column types to Manticore types and adds helpers for Manticore-only
types (`mva`, `mva64`, `floatVector`).

```php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection('manticore')->create('articles_rt', function (Blueprint $table) {
            // `id` is implicit in Manticore — no need to declare it.
            $table->text('body');                       // text  (full-text indexed)
            $table->string('title');                    // string attribute
            $table->integer('views');                   // integer
            $table->bigInteger('author_id');            // bigint
            $table->float('score');                     // float
            $table->boolean('published');               // bool
            $table->json('meta');                       // json
            $table->timestamp('created_at');            // timestamp
            $table->mva('tag_ids');                     // multi  (uint set)
            $table->mva64('big_ids');                   // multi64
            $table->floatVector('embedding', dims: 384); // float_vector for KNN
            $table->text('summary')->indexed()->stored();// spell out text options

            // Table-level Manticore options (trailing key='value' on CREATE TABLE)
            $table->minInfixLen(2);                       // infix search
            $table->morphology('stem_en');                // or ->manticoreOptions([...])
            // $table->engine = 'columnar';
        });
    }

    public function down(): void
    {
        Schema::connection('manticore')->dropIfExists('articles_rt');
    }
};
```

Type mapping: `string/char → string`, `text → text`, `integer/tinyInteger/smallInteger/mediumInteger → integer`,
`bigInteger → bigint`, `float/double/decimal → float`, `boolean → bool`, `json/jsonb → json`,
`timestamp/dateTime/date → timestamp`, plus `mva`, `mva64`, `floatVector`. Text columns
accept `->indexed()`/`->stored()`, and tables accept `minInfixLen()`, `morphology()` and
the generic `manticoreOptions([...])`.

`floatVector()` takes `dims`, `knnType` (default `hnsw`) and `similarity` (default `L2`,
e.g. `cosine`); any further column option goes in a trailing array, rendered as `key='value'`
— `hnsw_m`, `hnsw_ef_construction`, `quantization` (`8bit`, `1bit`, `1bitsimple`), and
anything else Manticore accepts there:

```php
$table->floatVector('content_vector', dims: 768, similarity: 'cosine', knnOptions: [
    'hnsw_m' => 16,
    'hnsw_ef_construction' => 200,
    'quantization' => '8bit',
]);
// → `content_vector` float_vector knn_type='hnsw' knn_dims='768' hnsw_similarity='cosine' hnsw_m='16' hnsw_ef_construction='200' quantization='8bit'
```

## Known limits & gotchas

- **No transactions / row locks.** Manticore has none; `lock()` / `->lockForUpdate()` are
  no-ops, and DB transactions should not be relied on.
- **`update()` vs `replace()`.** Manticore `UPDATE` only changes attribute (non-text)
  columns in place. To rewrite a full document (including `text` fields) use `replace()`.
- **`id` is special.** Manticore assigns the document id; migrations skip any `id` column,
  except a `uuid` one, which is how a table gets [UUID ids](#uuid-document-ids).
- **JOINs are limited.** Cross-index JOINs in Manticore are restricted; prefer denormalized
  indexes. Eager-loading relations across a relational connection still works as usual.
- **Qualified columns break.** Manticore rejects `` `table`.`column` ``. The driver leaves the
  model key unqualified, so `find()` works, but write any other column bare.
- **Prepared statements.** The driver forces emulated prepares (Manticore's server-side
  prepare support is limited), so bindings — including `MATCH(?)` — are interpolated by PDO.

## Tests

```bash
composer install
vendor/bin/pest --testsuite=Unit,Feature   # offline tests (no server needed)
# Integration tests require a running Manticore on the configured MySQL port
# (defaults to 9306; override with MANTICORE_HOST / MANTICORE_PORT):
MANTICORE_HOST=127.0.0.1 MANTICORE_PORT=9306 vendor/bin/pest --testsuite=Integration
```

A quick local Manticore for testing:

```bash
docker run --name manticore -p 9306:9306 -p 9308:9308 manticoresearch/manticore
```

Coverage (requires PCOV or Xdebug):

```bash
composer test:coverage        # full report
composer test:coverage-min    # fails under 95%
```

See [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) for the design and the SQL differences handled.

## License

[MIT](LICENSE)
