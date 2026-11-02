# Compatibility

What each part of Eloquent and the Query Builder does on Manticore. Every claim here is either
stated in the [Manticore manual](https://manual.manticoresearch.com) or proved against a real server
by [`ServerLimitsTest`](../tests/Integration/ServerLimitsTest.php), which fails when a new release
changes it.

## Works as usual

- `find`, `first`, `get`, `all`, `pluck`, `value`, `sole`, `exists`, `count`, `sum` and the other
  aggregates, `paginate`, `simplePaginate`, `cursorPaginate`, `chunk`, `chunkById`, `lazy`, `cursor`
- `where`, `orWhere`, `whereIn`, `whereNotIn`, `whereBetween`, `whereKey`, nested closures,
  `orderBy`, `latest`, `groupBy`, `having`, `limit`, `offset`, `select`, `selectRaw`
- `join`, `leftJoin`, `fromSub`
- `create`, `insert`, `insertGetId`, `update`, `delete`, `destroy`, `save`, `replicate`, `fresh`,
  `firstOrCreate`, `updateOrCreate`, `truncate`
- Scopes, casts, accessors, `$appends`, events, timestamps, route model binding, `SoftDeletes`
- `hasOne`, `hasMany`, `belongsTo`, `belongsToMany` with its pivot methods, `morphMany`, `morphTo`,
  `morphToMany`, `hasManyThrough`, `hasOneThrough`, on demand and with `with()`
- Migrations: `Schema::create`, `table`, `drop`, `rename`, `hasTable`, `getColumns`, `getTables`
- `DB::transaction()` for inserts, replaces and deletes on one table, with the limits below

## Translated for Manticore

| You write | What runs | Worth knowing | Why |
|---|---|---|---|
| A query without `limit()` | `limit` and `max_matches` covering every row | [High values cost memory](querying.md#row-limits) | [20 rows and a window of 1000 by default](https://manual.manticoresearch.com/Searching/Pagination) |
| `where('views', '10')`, `update(['slug' => 10])` | The value in the column's type | Read once per process with `DESC` | `ServerLimitsTest`: the server takes a quoted number only in some forms and ignores others on `UPDATE` |
| `whereNull('views')`, `update(['views' => null])` | `views = 0`, or `''` on a string | A real `0` counts as null | [`IS NULL` is for JSON attributes](https://manual.manticoresearch.com/Searching/Expressions) |
| `where('meta->a', 1)`, `update(['meta->a' => 2])` | `meta.a = 1` | [An update only takes numbers](https://manual.manticoresearch.com/Data_creation_and_modification/Updating_documents/UPDATE) | |
| `Post::query()->delete()`, `update()` without a filter | A `where` matching every document | [`truncate()` is much faster](https://manual.manticoresearch.com/Data_creation_and_modification/Deleting_documents) for emptying a table | `ServerLimitsTest`: `WHERE` is required |
| `distinct()` | `group by` the selected columns | | `ServerLimitsTest`: no `SELECT DISTINCT` |
| `upsert()` | `replace` | Only keyed by `id` and writing every column | `ServerLimitsTest`: no `ON DUPLICATE KEY` |
| `updateOrInsert()` | `update` without `limit` | Updates every match | [`UPDATE` syntax](https://manual.manticoresearch.com/Data_creation_and_modification/Updating_documents/UPDATE) has no `LIMIT` |
| `whereFullText()` | `matchText()` | | |
| Two `match()` calls | One `MATCH()` | | [At most one `MATCH()`](https://manual.manticoresearch.com/Searching/Full_text_matching/Basic_usage) |
| `` `table`.`column` `` in any clause | `table.column`, or the bare column for the query's own table | | `ServerLimitsTest`: backticks are rejected there |
| `table.*` of the left table | `*`, leaving the other table's columns out of the row | | `ServerLimitsTest` |
| `fromSub($query, 'alias')` | The sub-select without the alias | The outer query needs an `orderBy()`, and only takes `*`, order and limit | [Sub-selects](https://manual.manticoresearch.com/Searching/Sub-selects) |
| `count()` on a left join, `paginate()` on a grouped query | Counting the rows | Reads them all | `ServerLimitsTest` |
| A date in a `timestamp` column | A Unix integer | | [`timestamp`](https://manual.manticoresearch.com/Creating_a_table/Data_types) |
| `lock()`, `lockForUpdate()` | Nothing | | `ServerLimitsTest`: `FOR UPDATE` is rejected |

## Refused with a clear error

| You write | Error | Why | Instead |
|---|---|---|---|
| `rightJoin()`, `crossJoin()` | `LogicException` | [Only `INNER` and `LEFT`](https://manual.manticoresearch.com/Searching/Joining). The server would run a right join as an inner one, as `ServerLimitsTest` shows | Swap the tables and use `leftJoin()` |
| `increment()`, `decrement()` | `LogicException` | `ServerLimitsTest`: `UPDATE` takes no expressions | Read the value and write the new one, which is not atomic |
| `upsert()` keyed or writing otherwise | `LogicException` | `REPLACE` rewrites the whole document | `update()`, or `replaceWhereId()` |
| A Blueprint type with no equivalent, such as `binary` | `LogicException` naming the column | [Data types](https://manual.manticoresearch.com/Creating_a_table/Data_types) | Base64 in a `text` or `string` column, as the manual suggests |
| `change()` to anything but `bigInteger` | `LogicException` | [Only `int` to `bigint`](https://manual.manticoresearch.com/Updating_table_schema_and_settings) | Create a new table and reindex |
| A qualified name or JSON path with anything but plain names | `InvalidArgumentException` | They go out unquoted | |

## Fails with the server's error

| You write | Why | Instead |
|---|---|---|
| `whereHas`, `withCount`, `whereExists`, `whereIn` with a subquery | [Sub-selects only exist in `FROM`](https://manual.manticoresearch.com/Searching/Sub-selects) | Run the two queries, or `pluck()` the ids and use `whereIn()` |
| A second `join()` in one query | [The syntax has one `JOIN`](https://manual.manticoresearch.com/Searching/Joining), and `ServerLimitsTest` | Run the queries separately |
| A table alias in a join, `table.*` of the joined table | `ServerLimitsTest` | Name the tables and the columns |
| `union` | `ServerLimitsTest` | Run the queries separately |
| `like` | [Not among the operators of `WHERE`](https://manual.manticoresearch.com/Searching/Filters), and `ServerLimitsTest` | `matchText()`, or [`REGEX()`](https://manual.manticoresearch.com/Functions/String_functions) through an alias |
| `whereColumn`, `whereDate` and its relatives | [A function or expression cannot be used directly in `WHERE`](https://manual.manticoresearch.com/Searching/Filters) | Select it with an alias and filter on the alias, as below |
| `orWhere` next to `match()` | [`OR` with full-text matches is not supported](https://manual.manticoresearch.com/Searching/Full_text_matching/Basic_usage) | Write the alternative in the search syntax, as in `(a) \| (b)` |
| `insertOrIgnore()` | `ServerLimitsTest` | `firstOrCreate()` or `replace()` |
| `whereJsonContains()`, `whereJsonLength()` | `ServerLimitsTest` | [`ANY()` or `LENGTH()`](https://manual.manticoresearch.com/Functions/Arrays_and_conditions_functions) through an alias |
| `limit()` or `orderBy()` in `update()` or `delete()` | [`UPDATE` syntax](https://manual.manticoresearch.com/Data_creation_and_modification/Updating_documents/UPDATE), and `ServerLimitsTest` | Filter by what should change |
| `update()` of a `text` field | [Use `REPLACE`](https://manual.manticoresearch.com/Data_creation_and_modification/Updating_documents/UPDATE) | `replace()` or `replaceWhereId()` |
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
