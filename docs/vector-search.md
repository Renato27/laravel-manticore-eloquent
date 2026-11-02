# Vector search

Requires the column to be declared as a `floatVector` in the
[migration](migrations.md#vector-columns).

## Searching

```php
// knn(`embedding`, 10, (0.12, 0.98, …))  — pair with knn_dist() to read the score
Article::query()
    ->knn('embedding', $queryVector, k: 10)   // optional ef: ->knn(..., ef: 2000)
    ->selectRaw('*, knn_dist() as distance')
    ->get();

// "more like this document": pass a document id instead of a vector. Manticore returns
// the neighbours, never the source document itself. A UUID works too.
Article::query()->knn('embedding', $article->id, k: 10)->get();

// The 4th argument is the whole option block — knn(…, {ef=2000, rescore=1}). Any option
// Manticore accepts there goes through; float options need a float (3.0, not 3).
Article::query()
    ->knn('embedding', $queryVector, k: 10, ef: ['ef' => 2000, 'rescore' => true])
    ->get();
```

On a relational model with the `HasManticore` trait, start from `Company::manticore()` instead of
`Company::query()`.

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
