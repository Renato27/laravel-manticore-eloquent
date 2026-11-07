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
$result = Article::query()->match('cloud')->limit(20)->facet('category')->facet('year', 'published')->getWithFacets();

$result['rows'];                  // the first 20 matches, as models
$result['facets']['category'];    // a collection of rows with `category` and `count(*)`
$result['facets']['published'];   // named by the alias
```

Give `getWithFacets()` a `limit()`: without one it returns every match, as `get()` does (see
[Row limits](#row-limits)), while the facets are counted over all the matches either way.

A facet field must be a column or a JSON path such as `meta.author`, and an alias a plain name, since
they go into the SQL unquoted. The rest of Manticore's
[`FACET` syntax](https://manual.manticoresearch.com/Searching/Faceted_search), such as its own order
and limit, is explicit, with any alias written right after the field. A facet returns 20 groups unless
it sets a limit, and a limit past 1000 groups raises `max_matches` to it, which would cut it at 1000
otherwise:

```php
->facet(DB::raw('category ORDER BY COUNT(*) DESC LIMIT 50'))            // named `category`
->facet(DB::raw('year AS published ORDER BY year DESC LIMIT 10'))       // named `published`
->facet(DB::raw('tag ORDER BY COUNT(*) DESC LIMIT 5000'))               // max_matches=5000
->facet($request->input('group'))                                      // throws unless it's a plain name
```

A facet on a JSON path is named by the path, and so is its column, such as `meta.author`. Laravel's
`pluck('meta.author')` reads that as nested keys and finds nothing, so give the facet an alias:
`facet('meta.author', 'author')`.

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
The driver handles each of these on its own:

- **A query without `limit()`**, such as `all()`, `get()`, `pluck()`, `cursor()`, `getWithFacets()` or
  a relation, returns every row, as Eloquent promises. Without this, Manticore would cut it at 20
  rows without an error.
- **A query that reaches past the first 1000 rows**, such as page 101 of `paginate(10)` or
  `offset(1500)->limit(50)`, gets `max_matches` raised to `offset + limit`, so deep paging works.
  `paginate()` and `simplePaginate()` always set a limit, so the first rule never applies to them.
- **A facet whose limit reaches past 1000 groups** gets `max_matches` raised to that limit, as above.

Setting `maxMatches()` yourself caps the rows at that number, and the total `paginate()` shows as
well, so its last page is the last one with rows.

To cap queries without a limit instead of reading every row, set `default_limit` (or
`MANTICORE_DEFAULT_LIMIT`). It applies to every such query, relations included, so a relation larger
than it comes back cut short, as with Manticore's own default. `count()` and the total of `paginate()`
still count every row:

```php
// config/manticore-eloquent.php, or the connection in config/database.php
'default_limit' => 1000,
```

The manual warns that a high `max_matches` needs more memory. In one measurement, on 200,000
documents, reading them all cost `searchd` about 40 MB more than reading 20. That depends on the
columns read and the matches sorted, so measure on your data. On a large table, read in pages by id
with `chunkById()`, `lazyById()` or `cursorPaginate()`, or use the manual's
[scroll search](https://manual.manticoresearch.com/Searching/Pagination). `chunk()` pages by offset,
so each chunk raises `max_matches` further and costs more than the last. It is still the way through a
table with [UUID ids](uuid-document-ids.md), which take no range filter.

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

A join returns no more rows than its left table has documents, or 1000 if the table is smaller,
whatever `max_matches` says, and without an error. `count()` on a left join counts the rows it
returns, so it stops there too. The manual does not mention this, and its own example returns more
rows than the left table has documents, so `ServerLimitsTest` measures it. Where one document of the
left table matches many of the right one, put the larger side on the left, or run the two queries.

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
