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
- **`SoftDeletes` fails with an error,** because it filters with `deleted_at is null`.
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
- **`whereNull()` and `whereNotNull()` only work on JSON columns.** On most other types they fail
  with an error, and on a `string` column they run but **leave out rows that have a value**.
- **Timestamps are Unix integers.** Eloquent timestamps and date casts handle that for you, and
  so do dates in queries, as long as the table has both `created_at` and `updated_at`, which
  `$table->timestamps()` adds. Otherwise set `public $timestamps = false`. There are no null
  dates, so a document without one reads as 1970. And never write a date as a *string* where
  no cast converts it, as in `DB::table('articles')->insert(['created_at' => '2026-01-02'])`:
  Manticore **silently stores `0`**. Pass a date object or an integer instead.
- **Prepared statements.** The driver forces emulated prepares (Manticore's server-side
  prepare support is limited), so bindings — including `MATCH(?)` — are interpolated by PDO.
