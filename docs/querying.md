# Querying

Eloquent and the Query Builder work as usual. These helpers cover what only Manticore has.

The examples use a model that extends `ManticoreModel`. On a relational model with the
`HasManticore` trait, start from `Company::manticore()` instead of `Company::query()`, which
would go to your relational database.

## Full-text search and options

```php
Article::query()
    ->match('cloud native', 'title')   // MATCH('@title cloud native')
    ->where('views', '>', 100)
    ->option('ranker', 'sph04')        // OPTION ranker=sph04
    ->maxMatches(5000)                 // OPTION max_matches=5000
    ->facet('category')                // FACET category
    ->get();
```

`get()` returns the matches. To read the facets as well, use `getWithFacets()`:

```php
$result = Article::query()->match('cloud')->facet('category')->facet('year', 'published')->getWithFacets();

$result['rows'];                  // the matches, as models
$result['facets']['category'];    // a collection of rows with `category` and `count(*)`
$result['facets']['published'];   // named by the alias
```

`match()` takes Manticore's search syntax, such as `alpha -beta` or `@title cloud`, so a stray
`(` in it is an error. For text typed by a user, use `matchText()`, which
[escapes that syntax](https://manual.manticoresearch.com/Searching/Full_text_matching/Escaping), or
Laravel's `whereFullText()`, which does the same. The manual notes that `*` cannot be escaped when it
acts as a wildcard:

```php
Article::query()->matchText($request->input('q'))->get();
Article::query()->matchText($request->input('q'), 'title')->get();
Article::query()->whereFullText(['title', 'body'], $request->input('q'))->get();
```

Manticore [takes one `MATCH()` per query](https://manual.manticoresearch.com/Searching/Full_text_matching/Basic_usage),
so several calls are combined into it, and `OR` between a match and a filter is not supported, so
`orWhere()` next to `match()` fails with an error.

Option names and values are never bound — Manticore's `OPTION` and `knn()` blocks take bare
keywords, not quoted strings. There is no list of allowed options (the server keeps adding
them), so instead both sides are checked for shape: a name must be a plain identifier, and a
value must be a number, a bool, or a bare word. Anything richer is explicit:

```php
->option('ranker', DB::raw("expr('sum(lcs*user_weight)')"))  // passed through verbatim
->option('ranker', $request->input('ranker'))                // throws unless it's a bare word
```

## Row limits

Manticore [returns 20 rows and stops at 1000 by default](https://manual.manticoresearch.com/Searching/Pagination).
The driver takes care of both: a query without a limit, such as `all()`, `get()` or a relation, returns
every row, and `max_matches` is raised when you page further, so deep `paginate()`/`offset()` just
works. Setting `maxMatches()` yourself caps the results at that number.

The manual warns that a high `max_matches` needs more memory. Measured on 200,000 documents, reading
them all cost `searchd` about 40 MB, following the rows found and not the value set. On a large
table, prefer `limit()`, `chunk()` or the manual's
[scroll search](https://manual.manticoresearch.com/Searching/Pagination).

## JSON columns

Laravel's `->` paths work in filters, selects, ordering and updates:

```php
Article::where('meta->author->id', 7)->orderBy('meta->rank')->get();
Article::where('id', 1)->update(['meta->rank' => 5]);
```

A JSON path compares types strictly, so `where('meta->rank', '5')` does not find the number `5`. A
[partial update](https://manual.manticoresearch.com/Data_creation_and_modification/Updating_documents/UPDATE)
can only change a number that already exists at that path. `whereJsonContains()` and
`whereJsonLength()` fail with an error, since their SQL uses MySQL's functions, and
[`ANY()` and `LENGTH()`](https://manual.manticoresearch.com/Functions/Arrays_and_conditions_functions)
work through an alias, as [Compatibility](compatibility.md) shows.

## Joins and sub-selects

[`join()` and `leftJoin()`](https://manual.manticoresearch.com/Searching/Joining) work as usual, and
rows come back named as in MySQL:

```php
Article::join('authors', 'articles.author_id', '=', 'authors.id')
    ->select('articles.title', 'authors.name')
    ->where('authors.active', 1)
    ->get();
```

When both tables have a column, the left table's value wins. Each query takes one join.

[Sub-selects](https://manual.manticoresearch.com/Searching/Sub-selects) work through `fromSub()`. The
manual allows only an order and a limit outside, so the outer query needs an `orderBy()`, reads every
column with `get()`, and takes no `pluck()` or aggregate:

```php
$best = Article::query()->select('id', 'views')->orderBy('views', 'desc')->limit(100);

DB::connection('manticore')->query()->fromSub($best, 'best')->orderBy('id')->limit(10)->get();
```

## Highlighting

```php
Article::query()
    ->match('cloud native')
    ->highlight('body', ['before_match' => '<mark>', 'after_match' => '</mark>'])
    ->get();   // each row gets a `highlight` column (alias configurable)
```
