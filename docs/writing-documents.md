# Writing documents

`create()`, `insert()`, `update()` and `delete()` work as usual, with one difference that
matters: Manticore's `UPDATE` only changes attributes in place, so it cannot touch `text`
fields. To change those, use `replace()` or `replaceWhereId()`.

## Full replace

`replace()` runs `REPLACE INTO` and rewrites the whole document. Fields you leave out go back
to their defaults. It takes a single row or a list of rows, just like `insert()`:

```php
Article::query()->replace(['id' => 1, 'title' => 'New', 'body' => '…']);
```

Through an Eloquent builder this returns the builder. To get the `bool`, call it on
`DB::connection('manticore')->table('articles')`. A failed write throws either way.

## Partial replace

`replaceWhereId()` rewrites only the fields you pass and keeps the rest of the document,
including full-text fields that `update()` cannot change:

```php
DB::connection('manticore')->table('articles')->replaceWhereId(1, ['tag' => 'beta']);
// replace into `articles` set `tag` = ? where `id` = ?
```

Constraints that come from Manticore, not from this driver:

- It needs **Manticore Buddy**. It ships inside the `manticore` package from 25.0.0 on;
  before that it was the separate `manticore-extra` package.
- It filters by `id` only, so it writes one document per call.
- Every field of the table must be `stored` (the default).
- **On an id that does not exist it creates a document holding only the fields you
  passed**, with defaults everywhere else, and it does not report that. Check that the
  document exists first if that matters.
- **With a [UUID id](uuid-document-ids.md) it is not atomic.** Buddy only handles numeric ids,
  so when Manticore rejects the statement the driver reads the document, overlays your fields
  and rewrites all of it. A write to that document in between is lost, and vector values go
  through their text form, which keeps about 8 significant digits.
- **On a table with an [auto embeddings](vector-search.md#searching-by-text) column it is not
  atomic either, whatever the id.** Buddy's partial replace keeps the old vector, so the driver
  rewrites the whole document as above, leaving that column out so the server embeds the new
  text. The manual does not cover this; `AutoEmbeddingsTest` shows it.

## Multi-value attributes

`MvaCast` reads a `multi` or `multi64` attribute as a list of integers and writes one back:

```php
use ManticoreEloquent\Eloquent\Casts\MvaCast;

protected $casts = ['tag_ids' => MvaCast::class];

Article::create(['title' => 'Hello', 'tag_ids' => [1, 2]]);
Article::find(1)->tag_ids;                // [1, 2]
Article::where('tag_ids', 2)->get();      // documents holding 2 among their tags
```

The Query Builder also takes a list when writing, as in `insert(['tag_ids' => [1, 2]])`, and reads
it back as the string `'1,2'`.

`insert()`, `replace()` and `upsert()` take one row or a list of rows. An array under a key that
names a vector or multi-value column, in that column's shape, is that column's value, even when
its keys are named. So a batch must not label a row with the name of such a column if the row
could be read as its value: use other labels, or none.
Through a model, `upsert()` takes a list of rows, as in Laravel.
