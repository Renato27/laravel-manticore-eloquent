# Known limits

- **Eloquent timestamps don't work: set `public $timestamps = false`.** Eloquent writes dates
  as strings and a `timestamp` column holds a Unix integer. `update()` fails with a syntax
  error, and `create()` **silently stores `0`, which reads back as 1970**. The same happens
  when you write a column that has a `datetime` cast, although that cast is fine for reading.
  Write an integer instead, such as `now()->timestamp`.
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
- **Prepared statements.** The driver forces emulated prepares (Manticore's server-side
  prepare support is limited), so bindings — including `MATCH(?)` — are interpolated by PDO.
