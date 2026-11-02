# UUID document ids

Manticore can use a UUID as the document id, so a document can keep the primary key it has
in your main database. This needs Manticore 28.6.6 or later and a real-time table, and the
choice is made when the table is created: a numeric table cannot be converted with `ALTER`.

## Setting it up

Declare the id in the migration. It is the only column that is ever declared, and `uuid` is
only allowed on `id`:

```php
Schema::connection('manticore')->create('articles', function (Blueprint $table) {
    $table->uuid('id');
    $table->text('title');
    $table->integer('views');
});
// create table `articles` (`id` uuid, `title` text, `views` integer)
```

Then tell the model its key is a string, or use Laravel's `HasUuids` trait, which does that
and also generates the id:

```php
class Article extends ManticoreModel
{
    protected $guarded = [];
    public $timestamps = false;
    public $incrementing = false;
    protected $keyType = 'string';
}

$article = Article::create(['id' => '550e8400-e29b-41d4-a716-446655440000', 'title' => 'Hello', 'views' => 1]);
Article::find('550e8400-e29b-41d4-a716-446655440000');
Article::where('id', '550e8400-e29b-41d4-a716-446655440000')->first();
$article->update(['views' => 2]);
$article->delete();
```

## What differs from integer ids

- **Who generates the id is your choice.** To let Manticore do it, leave the id out and keep
  `$incrementing` on, with `$keyType = 'string'`: `create()` and `insertGetId()` return the UUID
  the server generated. With `$incrementing = false` the id comes from you or from `HasUuids`.
- **The id must be one Manticore accepts:** versions 1 to 8, variant `8`, `9`, `a` or `b`.
  The nil UUID is refused. Any case works, and it always reads back in lowercase.
- **No range or null filters on `id`,** so `chunkById()` and `lazyById()` fail; use `chunk()`.
  `=`, `!=` and `IN` work, and so does `orderBy('id')`.
- **`replaceWhereId()` and `knn()` take a UUID, with two extra costs.** Manticore only does
  these by numeric id, so the driver does the work: `replaceWhereId()` has the limits listed
  under [Partial replace](writing-documents.md#partial-replace), and `knn()` runs one more
  query to read the document's vector. A document that does not exist or has no vector gives
  no results.
- **`toSql()` shows `knn()` with the UUID,** while the query sends the vector. Don't send that SQL
  by hand, and never quote the UUID: Manticore reads a *quoted* string there as text to embed,
  which on an auto-embeddings column returns unrelated documents and reports no problem.
- **Before Manticore 29.2.5, naming a text column in the select returns it empty** on a UUID
  table, as in `select('id', 'title')`. `select *`, the default, is not affected.
