# Compatibility

What each part of Eloquent and the Query Builder does on Manticore. Everything here runs against
a real server in the test suite.

## Works as usual

- `find`, `first`, `get`, `all`, `pluck`, `value`, `sole`, `exists`, `count`, `sum` and the other
  aggregates, `paginate`, `simplePaginate`, `cursorPaginate`, `chunk`, `chunkById`, `lazy`, `cursor`
- `where`, `orWhere`, `whereIn`, `whereNotIn`, `whereBetween`, `whereKey`, nested closures,
  `orderBy`, `latest`, `groupBy`, `having`, `limit`, `offset`, `select`, `selectRaw`
- `create`, `insert`, `insertGetId`, `update`, `delete`, `destroy`, `save`, `replicate`, `fresh`,
  `firstOrCreate`, `updateOrCreate`, `truncate`
- Scopes, casts, accessors, `$appends`, events, timestamps, route model binding, `SoftDeletes`
- `hasOne`, `hasMany`, `belongsTo`, `morphMany`, `morphTo`, on demand and with `with()`
- Migrations: `Schema::create`, `table`, `drop`, `rename`, `hasTable`, `getColumns`, `getTables`
- `DB::transaction()` for inserts and deletes on one table, with the limits below

## Translated for Manticore

| You write | What runs | Worth knowing |
|---|---|---|
| A query without `limit()` | `limit` and `max_matches` covering every row | Manticore alone stops at 20 rows |
| `where('views', '10')`, `update(['slug' => 10])` | The value in the column's type | Read once per process with `DESC` |
| `whereNull('views')`, `update(['views' => null])` | `views = 0`, or `''` on a string | A real `0` counts as null |
| `where('meta->a', 1)`, `update(['meta->a' => 2])` | `meta.a = 1` | An update only changes a number that exists |
| `Post::query()->delete()`, `update()` without a filter | A `where` matching every document | |
| `distinct()` | `group by` the selected columns | |
| `upsert()` | `replace` | Only keyed by `id` and writing every column |
| `updateOrInsert()` | `update` without `limit` | Updates every match |
| `whereFullText()` | `matchText()` | |
| A date in a `timestamp` column | A Unix integer | |
| `lock()`, `lockForUpdate()` | Nothing | Manticore has no row locks |

## Refused with a clear error

| You write | Error | Instead |
|---|---|---|
| `increment()`, `decrement()` | `LogicException` | Read the value and write the new one, which is not atomic |
| `upsert()` keyed or writing otherwise | `LogicException` | `update()`, or `replaceWhereId()` |
| A Blueprint type with no equivalent, such as `binary` | `LogicException` naming the column | Another type |
| A JSON path with anything but names and indexes | `InvalidArgumentException` | |

## Fails with the server's error

Manticore does not have these, and the driver does not hide it.

| You write | Instead |
|---|---|
| `belongsToMany`, `hasManyThrough`, `whereHas`, `withCount`, `whereExists`, subqueries | Run the two queries yourself |
| `join()` | Write the statement by hand with `DB::select()` |
| `union` | Run the queries separately |
| `like` | `match()` or `matchText()`, with `minInfixLen()` for partial words |
| `whereColumn`, `whereDate` and its relatives | Filter on the values, with a date as a Unix integer |
| `orWhere` next to `match()` | Write the alternative in the search syntax, as in `(a) \| (b)` |
| `insertOrIgnore()` | `firstOrCreate()` or `replace()` |
| `whereJsonContains()`, `whereJsonLength()` | `selectRaw()` with Manticore's `ANY()` or `LENGTH()` |
| `limit()` or `orderBy()` in `update()` or `delete()` | Filter by what should change |
| `update()` of a `text` field | `replace()` or `replaceWhereId()` |
| `renameColumn()`, `change()` | Create a new table and reindex |

See [Known limits](known-limits.md) for the reasoning behind these.
