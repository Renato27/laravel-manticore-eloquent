# Laravel Manticore Eloquent

Use **Eloquent natively on Manticore Search**. This package registers Manticore as a
first-class Laravel database connection over the **MySQL protocol** (port `9306`), so the
*entire* Eloquent / Query Builder API works unchanged — `where`, `find`, `create`,
`update`, `delete`, `paginate`, `chunk`, scopes, casts, relations, migrations — plus
Manticore helpers (`match`, `knn`, `highlight`, `option`, `maxMatches`, `facet`, `replace`).

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
| Laravel | `^10.0 \| ^11.0 \| ^12.0` |
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

`max_matches` is raised automatically when you page beyond Manticore's default cap of
1000 rows, so deep `paginate()`/`offset()` just works.

#### KNN / vector search

Requires the column to be declared as a `floatVector` in the migration (see below).

```php
// knn(`embedding`, 10, (0.12, 0.98, …))  — pair with knn_dist() to read the score
Article::manticore()
    ->knn('embedding', $queryVector, k: 10)   // optional ef: ->knn(..., ef: 2000)
    ->selectRaw('*, knn_dist() as distance')
    ->get();
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

## Known limits & gotchas

- **No transactions / row locks.** Manticore has none; `lock()` / `->lockForUpdate()` are
  no-ops, and DB transactions should not be relied on.
- **`update()` vs `replace()`.** Manticore `UPDATE` only changes attribute (non-text)
  columns in place. To rewrite a full document (including `text` fields) use `replace()`.
- **`id` is special.** Manticore assigns the document id; migrations skip any `id` column.
- **JOINs are limited.** Cross-index JOINs in Manticore are restricted; prefer denormalized
  indexes. Eager-loading relations across a relational connection still works as usual.
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
