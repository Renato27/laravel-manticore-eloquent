# Known limits

- **Relations that need a join or a subquery fail with an error.** `hasOne`, `hasMany`,
  `belongsTo`, `morphMany` and `morphTo` work, on demand and with `with()`, including across a
  relational database in either direction. `belongsToMany`, `hasManyThrough`, `whereHas` and
  `withCount` do not, because Manticore has no subqueries and these rely on them or on a join.
  Run the two queries yourself instead.
- **`match()` on a relation collides with a method of Eloquent's own.** `$post->notes()->match('x')`
  throws a `TypeError`. Use `$post->notes()->getQuery()->match('x')`.
- **No transactions / row locks.** Manticore has none; `lock()` / `->lockForUpdate()` are
  no-ops, and DB transactions should not be relied on.
- **`increment()` and `decrement()` fail with an error.** Manticore's `UPDATE` takes values, not
  expressions like `views + 1`. Read the value and write the new one, which is not atomic.
- **`update()` vs `replace()`.** Manticore `UPDATE` only changes attribute (non-text)
  columns in place. To rewrite a full document (including `text` fields) use
  [`replace()`](writing-documents.md).
- **`id` is special.** Manticore assigns the document id; migrations skip any `id` column,
  except a `uuid` one, which is how a table gets [UUID ids](uuid-document-ids.md).
- **JOINs are limited.** Cross-index JOINs in Manticore are restricted; prefer denormalized
  indexes. `join()` from the Query Builder fails, because Manticore rejects the backticks it
  writes in `ON`, so write the statement by hand with `DB::select()`.
- **Qualified columns break.** Manticore rejects `` `table`.`column` ``. The driver takes the
  query's own table out of filters and updated columns, which is what `find()` and relations
  need. Elsewhere, such as in `orderBy()`, write the column bare.
- **There is no `NULL` outside JSON.** A column without a value holds `0` or an empty string, so
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
