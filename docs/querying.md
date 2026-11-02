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

`get()` returns the matches. Facets come back as an extra result set, so reading them usually
means going through the raw statement.

`match()` takes Manticore's search syntax, such as `alpha -beta` or `@title cloud`, so a stray
`(` in it is an error. For text typed by a user, use `matchText()`, which treats every character
as plain text, or Laravel's `whereFullText()`, which does the same:

```php
Article::query()->matchText($request->input('q'))->get();
Article::query()->matchText($request->input('q'), 'title')->get();
Article::query()->whereFullText(['title', 'body'], $request->input('q'))->get();
```

Manticore takes one `MATCH()` per query, so several calls are combined into it. It cannot be
combined with an `or`: `orWhere()` next to `match()` fails with an error.

Option names and values are never bound — Manticore's `OPTION` and `knn()` blocks take bare
keywords, not quoted strings. There is no list of allowed options (the server keeps adding
them), so instead both sides are checked for shape: a name must be a plain identifier, and a
value must be a number, a bool, or a bare word. Anything richer is explicit:

```php
->option('ranker', DB::raw("expr('sum(lcs*user_weight)')"))  // passed through verbatim
->option('ranker', $request->input('ranker'))                // throws unless it's a bare word
```

Manticore returns 20 rows and stops at 1000 by default. The driver takes care of both: a query
without a limit, such as `all()`, `get()` or a relation, returns every row, and `max_matches` is
raised when you page further, so deep `paginate()`/`offset()` just works. Setting `maxMatches()`
yourself caps the results at that number.

## JSON columns

Laravel's `->` paths work in filters, selects, ordering and updates:

```php
Article::where('meta->author->id', 7)->orderBy('meta->rank')->get();
Article::where('id', 1)->update(['meta->rank' => 5]);
```

Manticore compares types strictly, so `where('meta->rank', '5')` does not find the number `5`. An
update can only change a number that already exists at that path. `whereJsonContains()` and
`whereJsonLength()` fail with an error: Manticore has no equivalent filter.

## Highlighting

```php
Article::query()
    ->match('cloud native')
    ->highlight('body', ['before_match' => '<mark>', 'after_match' => '</mark>'])
    ->get();   // each row gets a `highlight` column (alias configurable)
```
