# Compatibility

What each part of Eloquent and the Query Builder does on Manticore. Every claim here is either
stated in the [Manticore manual](https://manual.manticoresearch.com) or proved against a real server
by [`ServerLimitsTest`](../tests/Integration/ServerLimitsTest.php), which fails when a new release
changes it.

## Works as usual

- `find`, `first`, `get`, `all`, `pluck`, `value`, `sole`, `exists`, `count`, `sum` and the other
  aggregates, `paginate`, `simplePaginate`, `cursorPaginate`, `chunk`, `chunkById`, `lazy`, `cursor`
- `where`, `orWhere`, `whereNot`, `whereAny`, `whereAll`, `whereIn`, `whereNotIn`, `whereBetween`,
  `whereNotBetween`, `whereKey`, `whereRaw`, `when`, nested closures
- `orderBy`, `orderByDesc`, `orderByRaw`, `reorder`, `latest`, `inRandomOrder`, `groupBy`, `groupByRaw`,
  `having`, `havingRaw`, `havingBetween`, `limit`, `offset`, `select`, `addSelect`, `selectRaw`,
  `each`, `lazyById`, `toRawSql`
- `join`, `leftJoin`, `fromSub`
- `create`, `insert`, `insertGetId`, `update`, `delete`, `destroy`, `save`, `saveOrFail`, `updateOrFail`,
  `deleteOrFail`, `saveQuietly`, `deleteQuietly`, `replicate`, `fresh`, `refresh`, `firstOrNew`, `firstOr`,
  `findOr`, `firstWhere`, `firstOrCreate`, `createOrFirst`, `updateOrCreate`, `truncate`, `Prunable`
- Scopes, casts, accessors, `$appends`, events, timestamps, route model binding, `SoftDeletes`
- `hasOne`, `hasMany`, `belongsTo`, `belongsToMany` with its pivot methods, `morphMany`, `morphTo`,
  `morphToMany`, `whereMorphedTo`, `whereNotMorphedTo`, `hasManyThrough`, `hasOneThrough`, `one()`
- Loading them on demand, with `with()`, `load()` and `loadMissing()`, constrained or by column, and
  writing through them with `save`, `saveMany`, `create`, `createMany`, `firstOrCreate`, `associate`,
  `dissociate`, `attach`, `detach`, `sync`, `syncWithPivotValues`, `syncWithoutDetaching` and `toggle`
- Migrations: `Schema::create`, `table`, `drop`, `rename`, `hasTable`, `hasColumn`, `hasColumns`,
  `hasIndex`, `getColumns`, `getColumnType`, `getIndexes`, `getTables`
- `DB::transaction()` for inserts, replaces and deletes on one table, with the limits below

## Translated for Manticore

| You write | What runs | Worth knowing | Why |
|---|---|---|---|
| A query without `limit()` | `limit` and `max_matches` covering every row, or `default_limit` when set | [High values cost memory](querying.md#row-limits) | [20 rows and a window of 1000 by default](https://manual.manticoresearch.com/Searching/Pagination) |
| `facet(DB::raw('tag LIMIT 5000'))` | `max_matches` raised to the facet's limit | | `RowLimitTest`: the groups stop at `max_matches`, 1000 by default |
| `where('views', '10')`, `update(['slug' => 10])` | The value in the column's type | Read once per process with `DESC` | `ServerLimitsTest`: the server takes a quoted number only in some forms and ignores others on `UPDATE` |
| `whereNull('views')`, `update(['views' => null])` | `views = 0`, or `''` on a string | A real `0` counts as null, and a `null` written reads back as `0` or `''`, except a date, enum or encrypted attribute on a model, which reads as `null` | [`IS NULL` is for JSON attributes](https://manual.manticoresearch.com/Searching/Expressions) |
| `where('meta->a', 1)`, `update(['meta->a' => 2])` | `meta.a = 1` | [An update only takes numbers](https://manual.manticoresearch.com/Data_creation_and_modification/Updating_documents/UPDATE) | |
| `Post::query()->delete()`, `update()` without a filter | A `where` matching every document | [`truncate()` is much faster](https://manual.manticoresearch.com/Data_creation_and_modification/Deleting_documents) for emptying a table | `ServerLimitsTest`: `WHERE` is required |
| `where('a', '<=>', 1)`, `whereNotMorphedTo()` | `a = 1` | | [`<=>` is not among the operators](https://manual.manticoresearch.com/Searching/Filters), and `ServerLimitsTest` |
| A decimal in an integer column | Rounded, as in MySQL | | `ServerLimitsTest`: `UPDATE` stores garbage without an error |
| A repeated `id` | `UniqueConstraintViolationException`, so `createOrFirst()` finds the document | The `id` is the only unique key | `ServerLimitsTest` |
| `distinct()` | `group by` the selected columns | | `ServerLimitsTest`: no `SELECT DISTINCT` |
| `upsert()` | `replace` | Only keyed by `id` and writing every column | `ServerLimitsTest`: no `ON DUPLICATE KEY` |
| `updateOrInsert()` | `update` without `limit` | Updates every match | [`UPDATE` syntax](https://manual.manticoresearch.com/Data_creation_and_modification/Updating_documents/UPDATE) has no `LIMIT` |
| `whereFullText()` | `matchText()` | | |
| `count('column')` | `count(*)` | Counts every row, as there is no `NULL` to skip | [`COUNT(*)` and `COUNT(DISTINCT)`](https://manual.manticoresearch.com/Searching/Grouping) |
| `$table->index()`, `unique()`, `primary()`, `foreign()`, `constrained()`, `fullText()` | The column, without the rest | **Nothing enforces uniqueness or a foreign key** | `ServerLimitsTest`. [Secondary indexes are on by default](https://manual.manticoresearch.com/Server_settings/Searchd) and a `text` field is [already indexed](https://manual.manticoresearch.com/Creating_a_table/Data_types) |
| `nullable()`, `default()`, `comment()` | The column, without the rest | A new document holds the type's empty value | `ServerLimitsTest` |
| `getForeignKeys()`, `getViews()` | An empty list | | `ServerLimitsTest` |
| Two `match()` calls | One `MATCH()` | | [At most one `MATCH()`](https://manual.manticoresearch.com/Searching/Full_text_matching/Basic_usage) |
| `` `table`.`column` `` in any clause | `table.column`, or the bare column for the query's own table | | `ServerLimitsTest`: backticks are rejected there |
| `table.*` of the left table | `*`, leaving the other table's columns out of the row | | `ServerLimitsTest` |
| `fromSub($query, 'alias')` | The sub-select without the alias | The outer query needs an `orderBy()`, and only takes `*`, order and limit | [Sub-selects](https://manual.manticoresearch.com/Searching/Sub-selects) |
| `count()` on a left join, `paginate()` on a grouped query | Counting the rows | Reads them all | `ServerLimitsTest` |
| A date in a `timestamp` column | A Unix integer | | [`timestamp`](https://manual.manticoresearch.com/Creating_a_table/Data_types) |
| `lock()`, `lockForUpdate()` | Nothing | | `ServerLimitsTest`: `FOR UPDATE` is rejected |
| `knn('embedding', 42)` | `knn(embedding, k, 42) and id != 42` | | `ServerLimitsTest`: without `id` in the select, the source document comes back |
| `replaceWhereId()` on a table with an auto-embeddings column | A whole `replace` without that column | Not atomic | `AutoEmbeddingsTest`: a partial `REPLACE` keeps the old vector |

## Refused with a clear error

| You write | Error | Why | Instead |
|---|---|---|---|
| `rightJoin()`, `crossJoin()` | `LogicException` | [Only `INNER` and `LEFT`](https://manual.manticoresearch.com/Searching/Joining). The server would run a right join as an inner one, as `ServerLimitsTest` shows | Swap the tables and use `leftJoin()` |
| `increment()`, `decrement()` | `LogicException` | `ServerLimitsTest`: `UPDATE` takes no expressions | Read the value and write the new one, which is not atomic |
| A word in a numeric column or `id`, including `HasUlids` on numeric ids, or invalid JSON | `InvalidArgumentException` naming the column | `ServerLimitsTest`: the server stores `0`, another id or nothing, without an error | The right type. For string keys, a [UUID id](uuid-document-ids.md) |
| `upsert()` keyed or writing otherwise | `LogicException` | `REPLACE` rewrites the whole document | `update()`, or `replaceWhereId()` |
| A Blueprint type with no equivalent, such as `binary` or `geometry` | `LogicException` naming the column | [Data types](https://manual.manticoresearch.com/Creating_a_table/Data_types) | Base64 in a `text` or `string` column, as the manual suggests |
| `change()` to anything but `bigInteger` | `LogicException` | [Only `int` to `bigint`](https://manual.manticoresearch.com/Updating_table_schema_and_settings) | Create a new table and reindex |
| A qualified name or JSON path with anything but plain names | `InvalidArgumentException` | They go out unquoted | |
| `knn()` by document id on a `floatVectorArray` column | `LogicException` | `ServerLimitsTest`: the server fails on it, and the manual does not cover it | Pass one of the document's vectors |
| A list inside a `floatVector`, `mva` or `mva64` value, or inside a `knn()` query vector, such as `[[0.1, 0.2], 0.3]` | `InvalidArgumentException` naming the column | Each part goes out cast to a number, and a list would be cast to `1` | A flat list, such as `[0.1, 0.2, 0.3]` |
| A flat list such as `[0.1, 0.2]`, a JSON object or a vector inside a vector in a `floatVectorArray` column | `InvalidArgumentException` naming the column | [A value is always an array of vectors](https://manual.manticoresearch.com/Creating_a_table/Data_types#Float-vector-array) | `[[0.1, 0.2]]` |
| `dims`, `similarity` or `knnOptions` on a `floatVectorArray` with `knnType: null` | `InvalidArgumentException` naming the column | `ServerLimitsTest`: the server drops them, a model too, and the manual only says the column [needs no options](https://manual.manticoresearch.com/Creating_a_table/Data_types#Float-vector-array) | A `knnType`, or no options |

## Fails with the server's error, or behaves as the server does

| You write | Why | Instead |
|---|---|---|
| `has`, `whereHas`, `doesntHave`, `whereRelation`, `whereAttachedTo`, `withWhereHas`, `withCount`, `withSum` and the other aggregates, `withExists`, `loadCount`, `latestOfMany`, `oldestOfMany`, `ofMany`, `whereExists`, `whereIn` with a subquery, `selectSub`, `joinSub`, `addSelect` or `orderBy` with a query, `insertUsing` | [Sub-selects only exist in `FROM`](https://manual.manticoresearch.com/Searching/Sub-selects) | Run the two queries, or `pluck()` the ids and use `whereIn()`. For the latest of a relation, `$user->posts()->latest()->first()` |
| A second `join()` in one query | [The syntax has one `JOIN`](https://manual.manticoresearch.com/Searching/Joining), and `ServerLimitsTest` | Run the queries separately |
| A join whose rows outnumber the documents of its left table, and its `count()` | [Rows stop at that number, or at 1000 for a smaller table, without an error](querying.md#joins-and-sub-selects). The manual does not say so, and `ServerLimitsTest` measures it | Put the larger side on the left, or run the queries separately |
| A table alias in a join, `table.*` of the joined table | `ServerLimitsTest` | Name the tables and the columns |
| `union` | `ServerLimitsTest` | Run the queries separately |
| `like`, `whereLike` | [Not among the operators of `WHERE`](https://manual.manticoresearch.com/Searching/Filters), and `ServerLimitsTest` | `matchText()`, or [`REGEX()`](https://manual.manticoresearch.com/Functions/String_functions) through an alias |
| `whereColumn`, `whereBetweenColumns`, `whereValueBetween`, `whereDate`, `whereYear`, `whereMonth`, `whereDay`, `whereTime` | [A function or expression cannot be used directly in `WHERE`](https://manual.manticoresearch.com/Searching/Filters) | `whereBetween('created_at', ['2026-03-01 00:00:00', '2026-03-01 23:59:59'])` for a date, or select it with an alias and filter on the alias, as below |
| `max()` or `min()` of a string | `ServerLimitsTest` | Order by it and take the first row |
| `orWhere` next to `match()` | [`OR` with full-text matches is not supported](https://manual.manticoresearch.com/Searching/Full_text_matching/Basic_usage) | Write the alternative in the search syntax, as in `(a) \| (b)` |
| `insertOrIgnore()` | `ServerLimitsTest` | `firstOrCreate()` or `replace()` |
| `whereJsonContains()`, `whereJsonLength()` | `ServerLimitsTest` | [`ANY()` or `LENGTH()`](https://manual.manticoresearch.com/Functions/Arrays_and_conditions_functions) through an alias |
| `whereJsonContainsKey()` | Laravel writes it with MySQL's `json_contains_path()` | `whereNotNull('meta->key')` |
| `whereVectorSimilarTo()` and its relatives | [PostgreSQL only, in Laravel](https://laravel.com/docs/12.x/queries#vector-similarity-clauses) | `knn()` |
| `limit()` or `orderBy()` in `update()` or `delete()`, and so `MassPrunable` | [`UPDATE` syntax](https://manual.manticoresearch.com/Data_creation_and_modification/Updating_documents/UPDATE), and `ServerLimitsTest` | Filter by what should change. Use `Prunable` |
| `update()` of a `text` field | [Use `REPLACE`](https://manual.manticoresearch.com/Data_creation_and_modification/Updating_documents/UPDATE) | `replace()` or `replaceWhereId()` |
| An out-of-range number | [Trimmed to its lower 32 bits](https://manual.manticoresearch.com/Data_creation_and_modification/Updating_documents/UPDATE), without an error, so `-1` reads as `4294967295` | A `bigInteger` column |
| `$table->vector()` | Laravel refuses it on this driver | `floatVector()`, or `floatVectorArray()` for several vectors per document |
| `where()`, `whereNull()` or `orderBy()` on a `floatVectorArray` column | [It cannot be used in regular filters or sorting](https://manual.manticoresearch.com/Creating_a_table/Data_types#Float-vector-array) | `knn()` |
| `update()` of a `floatVectorArray` column with KNN | [Use `REPLACE`, as with a KNN-indexed `float_vector`](https://manual.manticoresearch.com/Creating_a_table/Data_types#Float-vector-array) | `replace()`, or a column with `knnType: null` |
| `renameColumn()` | [`ALTER` adds, drops and widens](https://manual.manticoresearch.com/Updating_table_schema_and_settings), and `ServerLimitsTest` | Create a new table and reindex |

The alias form the manual gives for filtering on an expression works through `selectRaw()`:

```php
Article::selectRaw('*, YEAR(created_at) as year')->where('year', 2026)->get();
Article::selectRaw("*, REGEX(slug, '^intro') as hit")->where('hit', 1)->get();
Article::selectRaw('*, ANY(x = 7 FOR x IN meta.tags) as hit')->where('hit', 1)->get();
```

`count()` and `paginate()` leave the select out, as they do on any database, so the alias is gone and
they fail with an error. Use `get()` there.

See [Known limits](known-limits.md) for the details that matter most.
