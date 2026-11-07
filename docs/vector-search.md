# Vector search

Requires the column to be declared as a `floatVector`, or a `floatVectorArray` for
[several vectors per document](#several-vectors-per-document), in the
[migration](migrations.md#vector-columns).

## Searching

```php
// knn(`embedding`, 10, (0.12, 0.98, …))  — pair with knn_dist() to read the score
Article::query()
    ->knn('embedding', $queryVector, k: 10)   // optional ef: ->knn(..., ef: 2000)
    ->selectRaw('*, knn_dist() as distance')
    ->get();

// "more like this document": pass a document id instead of a vector. It returns the
// neighbours without the source document, whatever the select lists. A UUID works too.
Article::query()->knn('embedding', $article->id, k: 10)->get();

// The 4th argument is the whole option block — knn(…, {ef=2000, rescore=1}). Any option
// Manticore accepts there goes through; float options need a float (3.0, not 3).
Article::query()
    ->knn('embedding', $queryVector, k: 10, ef: ['ef' => 2000, 'rescore' => true])
    ->get();
```

A numeric id adds `id != ?` to the query. Manticore only leaves the source document out when the
select lists `id`, so `pluck('title')` would bring it back first, at distance 0. The manual does not
say either way, so `ServerLimitsTest` shows it.

On a relational model with the `HasManticore` trait, start from `Company::manticore()` instead of
`Company::query()`.

## Searching by text

A column with [auto embeddings](https://manual.manticoresearch.com/Searching/KNN) gets its vectors
from the server, which embeds the fields in `from` with the model in `model_name`. The model can be
local, or remote with an API key. A gateway that speaks the OpenAI API, such as LiteLLM, takes the
`provider:model` form with `api_url`:

```php
$table->text('title');
$table->floatVector('embedding', similarity: 'cosine', knnOptions: [
    'model_name' => 'openai:embed',   // the part before ':' only selects the request format
    'from' => 'title',
    'api_key' => env('EMBEDDINGS_KEY'),
    'api_url' => 'http://litellm:4000/v1/embeddings',
    'api_timeout' => 30,
]);
```

`knnText()` searches such a column by text, which the server embeds with the same model:

```php
// knn(`embedding`, 10, ?)  with the text bound
Article::query()->knnText('embedding', 'leather purse', k: 10)->get();
```

It takes the same `ef` and option block as `knn()`, and works on a `floatVectorArray` too. Pass the
text to `knnText()`, not to `knn()`: there a string is a document id.

Writes leave the column out, and the server fills it. `replaceWhereId()` gets the vector embedded
again from the new text, as described in [Partial replace](writing-documents.md#partial-replace).

## Writing vectors

Writing vectors needs the `VectorCast`, because Manticore only accepts a vector as a bare
tuple literal — `(0.1, 0.2)` — which no bound parameter can express:

```php
use ManticoreEloquent\Eloquent\Casts\VectorCast;

class Article extends ManticoreModel
{
    protected $guarded = [];
    public $timestamps = false;
    protected $casts = ['embedding' => VectorCast::class];
}

Article::create(['embedding' => [0.1, 0.2, 0.3]]);  // → values ((0.1, 0.2, 0.3))
Article::query()->where('id', 1)->first()->embedding; // → [0.1, 0.2, 0.3]
```

## Stored vectors

Two things to know about vectors once they are stored: a `cosine` column normalizes them,
so the values you read back are the unit vector, not what you wrote (`L2` round-trips
exactly); and Manticore refuses `UPDATE` on a KNN-indexed column, so changing a vector
means rewriting the row with `replace()`:

```php
DB::connection('manticore')->table('articles')
    ->replace([(new Article(['id' => 1, 'embedding' => [0.4, 0.5, 0.6]]))->getAttributes()]);
```

## Several vectors per document

A [`float_vector_array`](https://manual.manticoresearch.com/Creating_a_table/Data_types#Float-vector-array)
column keeps several vectors on one document, such as the chunks of an article or the photos of a
product. It needs Manticore 29.9.0 or later. Declare it with
[`floatVectorArray()`](migrations.md#vector-columns) and read it with the `VectorArrayCast`:

```php
use ManticoreEloquent\Eloquent\Casts\VectorArrayCast;

class Article extends ManticoreModel
{
    protected $guarded = [];
    public $timestamps = false;
    protected $casts = ['chunks' => VectorArrayCast::class];
}

Article::create(['chunks' => [[0.1, 0.2, 0.3], [0.4, 0.5, 0.6]]]); // → values ([[0.1, 0.2, 0.3], [0.4, 0.5, 0.6]])
Article::query()->where('id', 1)->first()->chunks;                // → [[0.1, 0.2, 0.3], [0.4, 0.5, 0.6]]

Article::query()->knn('chunks', $queryVector, k: 10)->get();
```

The query builder writes the same arrays without the cast, which is what reads them back as
arrays. `[]`, `null` and a column left out all store no vectors, and read back as `[]`.

`knn()` takes one query vector, as on a `floatVector`. As
[the manual](https://manual.manticoresearch.com/Searching/KNN#Multiple-vectors-per-document)
describes, a document matches if any of its vectors is near the query, comes back once, and
`knn_dist()` is the distance to its closest vector. A document with no vectors never matches.

What differs from a `floatVector`:

- **`knn()` by document id throws a `LogicException`.** Manticore fails on it for this type. The
  manual does not cover it, so `ServerLimitsTest` shows it. Pass one of the document's vectors.
- **The server checks the widths.** On a KNN column every vector has `dims` entries, and within one
  value all vectors have the same width, as
  [the manual](https://manual.manticoresearch.com/Creating_a_table/Data_types#Float-vector-array)
  says. A flat list such as `[0.1, 0.2]` throws an `InvalidArgumentException`, since it cannot be split
  into vectors, and so does a JSON object such as `{"embedding": [0.1, 0.2]}`, whose keys would be lost.
- **A column without KNN takes `update()`.** On a KNN column, rewrite the row with `replace()`, as
  above.
