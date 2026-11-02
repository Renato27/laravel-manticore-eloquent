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

## Highlighting

```php
Article::query()
    ->match('cloud native')
    ->highlight('body', ['before_match' => '<mark>', 'after_match' => '</mark>'])
    ->get();   // each row gets a `highlight` column (alias configurable)
```
