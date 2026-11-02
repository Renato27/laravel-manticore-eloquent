# Known limits

- **`whereHas()` and `withCount()` fail with an error.** Laravel writes them with a subquery in
  `WHERE` or in the select list, and Manticore only takes
  [sub-selects in `FROM`](https://manual.manticoresearch.com/Searching/Sub-selects). Run the two
  queries instead. Every relation type loads, on demand and with `with()`, including `belongsToMany`
  and `hasManyThrough`, and across a relational database for those without a join, in either
  direction.
- **`match()` on a relation collides with a method of Eloquent's own.** `$post->notes()->match('x')`
  throws a `TypeError`. Use `$post->notes()->getQuery()->match('x')` or `matchText()`.
- **[Transactions](https://manual.manticoresearch.com/Data_creation_and_modification/Transactions)
  are limited, and there are no row locks.** `DB::transaction()` commits and rolls back inserts,
  replaces and deletes on one table. Writing to a second table fails with an error. `update()` **is
  not rolled back**, and a transaction cannot read its own writes. `lock()` and `lockForUpdate()` do
  nothing.
- **`update()` and `delete()` take no `limit()` or `orderBy()`,** which fail with an error. For the
  same reason, `updateOrInsert()` updates every document that matches, not just the first.
- **`increment()` and `decrement()` throw a `LogicException`.** Manticore's `UPDATE` takes values,
  not expressions like `views + 1`. Read the value and write the new one, which is not atomic.
- **`upsert()` is a `REPLACE`.** It only runs keyed by `id` and writing every column, and throws a
  `LogicException` otherwise, including on a model with timestamps, where a `REPLACE` would reset
  `created_at`. `insertOrIgnore()` fails with an error.
- **`distinct()` becomes a `GROUP BY`** of the selected columns.
- **Other SQL fails with the server's error,** such as `union`, `like`, `whereColumn` and `whereDate`.
  [Compatibility](compatibility.md) has the full list, with the manual's alternative for each.
- **`update()` vs `replace()`.** Manticore `UPDATE` only changes attribute (non-text)
  columns in place. To rewrite a full document (including `text` fields) use
  [`replace()`](writing-documents.md).
- **`id` is special.** Manticore assigns the document id; migrations skip any `id` column,
  except a `uuid` one, which is how a table gets [UUID ids](uuid-document-ids.md).
- **[Joins](https://manual.manticoresearch.com/Searching/Joining) are `INNER` or `LEFT`, and one per
  query, without table aliases.** `rightJoin()` and `crossJoin()` throw a `LogicException`, since the
  server would run a right join as an inner one without an error.
- **There is no [`NULL` outside JSON](https://manual.manticoresearch.com/Searching/Expressions).** A column without a value holds `0` or an empty string, so
  the driver filters and writes those instead: `whereNull('views')` is `views = 0`, and writing
  `null` stores the empty value. A real `0` therefore also counts as null. `SoftDeletes` works on
  top of this. A `text` field cannot be filtered at all, so `whereNull()` on one fails with an
  error, and so does any null filter on a `uuid` id.
- **Timestamps are Unix integers.** Eloquent timestamps and date casts handle that for you, and
  so do dates in queries, as long as the table has both `created_at` and `updated_at`, which
  `$table->timestamps()` adds. Otherwise set `public $timestamps = false`. A date attribute
  without a value reads as `null` on a model, and as `0` from the Query Builder.
- **A value takes the type of its column.** Manticore does not convert types, so the driver reads
  each table's types with `DESC`, once per process, and sends `'10'` as a number to a numeric
  column, `10` as a string to a string column and a date string as a Unix integer to a timestamp
  column. A schema change made in this process is picked up by itself. One made elsewhere needs
  a new process, such as a worker restart.
- **Prepared statements.** The driver forces emulated prepares (Manticore's server-side
  prepare support is limited), so bindings — including `MATCH(?)` — are interpolated by PDO.
