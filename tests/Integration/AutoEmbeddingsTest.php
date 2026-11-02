<?php

use Illuminate\Support\Facades\DB;

/**
 * Downloads an embedding model, so it only runs when asked to:
 * MANTICORE_TEST_EMBEDDINGS=1 vendor/bin/pest tests/Integration/AutoEmbeddingsTest.php
 */
beforeEach(function () {
    if (! getenv('MANTICORE_TEST_EMBEDDINGS')) {
        $this->markTestSkipped('Set MANTICORE_TEST_EMBEDDINGS=1 to run it.');
    }

    DB::connection('manticore')->statement('DROP TABLE IF EXISTS it_embedded');
    DB::connection('manticore')->statement(
        "CREATE TABLE it_embedded (id uuid, title text, tag string attribute, vec float_vector knn_type='hnsw' "
        ."hnsw_similarity='l2' model_name='sentence-transformers/all-MiniLM-L6-v2' from='title')"
    );
});

afterEach(function () {
    if (getenv('MANTICORE_TEST_EMBEDDINGS')) {
        DB::connection('manticore')->statement('DROP TABLE IF EXISTS it_embedded');
    }
});

it('regenerates the vector when a uuid document is partially replaced', function () {
    $table = fn () => DB::connection('manticore')->table('it_embedded');
    $bag = '550e8400-e29b-41d4-a716-446655440000';

    $table()->insert(['id' => $bag, 'title' => 'yellow bag', 'tag' => 'x']);
    $table()->insert(['id' => '660e8400-e29b-41d4-a716-446655440001', 'title' => 'yellow handbag', 'tag' => 'x']);
    $table()->insert(['id' => '770e8400-e29b-41d4-a716-446655440002', 'title' => 'quantum physics lecture', 'tag' => 'x']);

    expect($table()->knn('vec', $bag, k: 5)->pluck('title')->all())->toBe(['yellow handbag', 'quantum physics lecture']);

    $table()->replaceWhereId($bag, ['title' => 'quantum mechanics course']);

    $document = $table()->where('id', $bag)->first();

    expect($document->title)->toBe('quantum mechanics course')
        ->and($document->tag)->toBe('x')
        ->and($table()->knn('vec', $bag, k: 5)->pluck('title')->all())->toBe(['quantum physics lecture', 'yellow handbag']);
});
