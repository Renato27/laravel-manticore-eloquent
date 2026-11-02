# Known limits

- **Relations only work when the related model is in your relational database.** Eloquent
  qualifies the key (`` `notes`.`post_id` ``) and Manticore rejects that, so any relation whose
  query runs on Manticore fails with an error. Query that side directly, as in
  `Note::where('post_id', $post->id)->get()`.
- **No transactions / row locks.** Manticore has none; `lock()` / `->lockForUpdate()` are
  no-ops, and DB transactions should not be relied on.
- **`update()` vs `replace()`.** Manticore `UPDATE` only changes attribute (non-text)
  columns in place. To rewrite a full document (including `text` fields) use
  [`replace()`](writing-documents.md).
- **`id` is special.** Manticore assigns the document id; migrations skip any `id` column,
  except a `uuid` one, which is how a table gets [UUID ids](uuid-document-ids.md).
- **JOINs are limited.** Cross-index JOINs in Manticore are restricted; prefer denormalized
  indexes.
- **Qualified columns break.** Manticore rejects `` `table`.`column` ``. The driver leaves the
  model key unqualified, so `find()` works, but write any other column bare.
- **Timestamps are Unix integers.** Eloquent timestamps and date casts handle that for you, and
  so do dates in queries, as long as the table has both `created_at` and `updated_at`, which
  `$table->timestamps()` adds. Otherwise set `public $timestamps = false`. There are no null
  dates, so a document without one reads as 1970. And never write a date as a *string* where
  no cast converts it, as in `DB::table('articles')->insert(['created_at' => '2026-01-02'])`:
  Manticore **silently stores `0`**. Pass a date object or an integer instead.
- **Prepared statements.** The driver forces emulated prepares (Manticore's server-side
  prepare support is limited), so bindings — including `MATCH(?)` — are interpolated by PDO.
