# Laravel Manticore Eloquent

[![Latest version][badge-version]][packagist]
[![Total downloads][badge-downloads]][packagist-stats]
[![Tests passing][badge-tests]][workflow]
[![CI][badge-ci]][workflow]
[![PHP version][badge-php]][packagist]
[![Laravel version][badge-laravel]][packagist]
[![License][badge-license]](LICENSE)

Use **Eloquent natively on Manticore Search**. This package registers Manticore as a
first-class Laravel database connection over the **MySQL protocol** (port `9306`), so
`where`, `find`, `create`, `update`, `delete`, `paginate`, `chunk`, scopes, casts, relations
and migrations work the way you already write them, plus Manticore helpers (`match`, `matchText`, `knn`,
`highlight`, `option`, `maxMatches`, `facet`, `replace`).

Manticore speaks the MySQL wire protocol, and all of Eloquent is built on a `Connection` +
`Grammar`. So instead of re-implementing Eloquent method by method, this is a thin Manticore
grammar that inherits the whole framework.

## Requirements

| Dependency | Version |
|---|---|
| PHP | `^8.1` (with `pdo_mysql`) |
| Laravel | `^10.0 \| ^11.0 \| ^12.0 \| ^13.0` |
| Manticore Search | Tested on `28.6.6` and later, with the MySQL protocol enabled (default port `9306`) |

## Installation

```bash
composer require renatomaldonado/laravel-manticore-eloquent
```

Configure the connection in `.env`. Every value is optional:

```dotenv
MANTICORE_CONNECTION=manticore
MANTICORE_HOST=127.0.0.1
MANTICORE_PORT=9306        # MySQL protocol port
MANTICORE_USERNAME=
MANTICORE_PASSWORD=
MANTICORE_ENGINE=          # columnar, rowwise or empty
MANTICORE_DEFAULT_LIMIT=   # rows of a query without limit(); empty = every row
```

The package auto-registers a `manticore` connection in `config/database.connections`, so you
don't have to edit `config/database.php`. To change the config file itself, publish it:

```bash
php artisan vendor:publish --provider="ManticoreEloquent\ManticoreEloquentServiceProvider" --tag=config
```

## Usage

### Option A — a model that lives entirely in Manticore

Extend `ManticoreModel`; its default connection is Manticore.

```php
use ManticoreEloquent\Eloquent\ManticoreModel;

class Article extends ManticoreModel
{
    protected $table = 'articles_rt';
    protected $guarded = [];
    protected $casts = ['published' => 'boolean'];
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

## Before you start

Manticore is not MySQL. The driver translates what it can: a query without a limit returns every
row, values take the type of their column, and `whereNull()`, `SoftDeletes` and `belongsToMany` work.
What the server does not have fails with an error, such as `update()` of a full-text field or
`whereHas()`. [Compatibility](docs/compatibility.md) lists it all, with the manual's reference for
each limit.

## Documentation

| Guide | What it covers |
|---|---|
| [Querying](docs/querying.md) | Full-text `match` and `matchText`, `option`, `maxMatches`, `facet`, `highlight`, JSON |
| [Writing documents](docs/writing-documents.md) | `update` vs `replace`, partial `replaceWhereId`, multi-value attributes |
| [Vector search](docs/vector-search.md) | `knn`, "more like this document", `VectorCast`, several vectors per document |
| [UUID document ids](docs/uuid-document-ids.md) | The same primary key in your database and in Manticore |
| [Migrations](docs/migrations.md) | Column types, vector columns, table options |
| [Compatibility](docs/compatibility.md) | What works, what is translated and what fails |
| [Known limits](docs/known-limits.md) | What differs from MySQL: relations, timestamps, transactions, JOINs |
| [Architecture](docs/ARCHITECTURE.md) | The design and the SQL differences handled |

## Related packages

- `renatomaldonado/laravel-manticore-sync` keeps a relational database in sync with Manticore
  automatically.
- `renatomaldonado/manticore-laravel-search` is an older, separate library (HTTP/JSON fluent
  builder + consolidation). If you use it, keep using it for those features: this package
  sits beside it and does not replace it.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for how to run the tests.

## License

[MIT](LICENSE)

[packagist]: https://packagist.org/packages/renatomaldonado/laravel-manticore-eloquent
[packagist-stats]: https://packagist.org/packages/renatomaldonado/laravel-manticore-eloquent/stats
[workflow]: https://github.com/Renato27/laravel-manticore-eloquent/actions/workflows/tests.yml
[badge-version]: https://img.shields.io/packagist/v/renatomaldonado/laravel-manticore-eloquent
[badge-downloads]: https://img.shields.io/packagist/dt/renatomaldonado/laravel-manticore-eloquent
[badge-tests]: https://img.shields.io/endpoint?url=https%3A%2F%2Fraw.githubusercontent.com%2FRenato27%2Flaravel-manticore-eloquent%2Fbadges%2Ftests.json
[badge-ci]: https://img.shields.io/github/actions/workflow/status/Renato27/laravel-manticore-eloquent/tests.yml?branch=master&label=CI
[badge-php]: https://img.shields.io/packagist/dependency-v/renatomaldonado/laravel-manticore-eloquent/php
[badge-laravel]: https://img.shields.io/packagist/dependency-v/renatomaldonado/laravel-manticore-eloquent/illuminate/database?label=laravel
[badge-license]: https://img.shields.io/packagist/l/renatomaldonado/laravel-manticore-eloquent
