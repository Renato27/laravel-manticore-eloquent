# Migrations

Manticore real-time indexes are created with standard Laravel migrations. The schema
grammar maps Laravel column types to Manticore types and adds helpers for Manticore-only
types (`mva`, `mva64`, `floatVector`).

```php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection('manticore')->create('articles_rt', function (Blueprint $table) {
            // `id` is implicit in Manticore — no need to declare it.
            $table->text('body');                       // text  (full-text indexed)
            $table->string('title');                    // string attribute
            $table->integer('views');                   // integer
            $table->bigInteger('author_id');            // bigint
            $table->float('score');                     // float
            $table->boolean('published');               // bool
            $table->json('meta');                       // json
            $table->timestamp('created_at');            // timestamp
            $table->mva('tag_ids');                     // multi  (uint set)
            $table->mva64('big_ids');                   // multi64
            $table->floatVector('embedding', dims: 384); // float_vector for KNN
            $table->text('summary')->indexed()->stored();// spell out text options

            // Table-level Manticore options (trailing key='value' on CREATE TABLE)
            $table->minInfixLen(2);                       // infix search
            $table->morphology('stem_en');                // or ->manticoreOptions([...])
            // $table->engine = 'columnar';
        });
    }

    public function down(): void
    {
        Schema::connection('manticore')->dropIfExists('articles_rt');
    }
};
```

To use UUIDs as document ids, declare the id with `$table->uuid('id')`. See
[UUID document ids](uuid-document-ids.md).

## Type mapping

| Laravel | Manticore |
|---|---|
| `string`, `char` | `string` |
| `text` | `text` |
| `integer`, `tinyInteger`, `smallInteger`, `mediumInteger` | `integer` |
| `bigInteger` | `bigint` |
| `float`, `double`, `decimal` | `float` |
| `boolean` | `bool` |
| `json`, `jsonb` | `json` |
| `timestamp`, `dateTime`, `date` | `timestamp` |
| `mva` | `multi` |
| `mva64` | `multi64` |
| `floatVector` | `float_vector` |
| `uuid` (only on `id`) | `uuid` |

A `timestamp` column holds a Unix integer, so write `now()->timestamp` to it rather than a
date. See [Known limits](known-limits.md).

Text columns accept `->indexed()`/`->stored()`, and tables accept `minInfixLen()`,
`morphology()` and the generic `manticoreOptions([...])`.

## Vector columns

`floatVector()` takes `dims`, `knnType` (default `hnsw`) and `similarity` (default `L2`,
e.g. `cosine`); any further column option goes in a trailing array, rendered as `key='value'`
— `hnsw_m`, `hnsw_ef_construction`, `quantization` (`8bit`, `1bit`, `1bitsimple`), and
anything else Manticore accepts there:

```php
$table->floatVector('content_vector', dims: 768, similarity: 'cosine', knnOptions: [
    'hnsw_m' => 16,
    'hnsw_ef_construction' => 200,
    'quantization' => '8bit',
]);
// → `content_vector` float_vector knn_type='hnsw' knn_dims='768' hnsw_similarity='cosine' hnsw_m='16' hnsw_ef_construction='200' quantization='8bit'
```
