<?php

use Illuminate\Support\Facades\DB;

/**
 * Downloads an embedding model, so phpunit.xml leaves its group out of a normal run. Run it with:
 * vendor/bin/pest --group=embeddings
 */
beforeEach(function () {
    DB::connection('manticore')->statement('DROP TABLE IF EXISTS it_embedded');
    DB::connection('manticore')->statement(
        "CREATE TABLE it_embedded (id uuid, title text, tag string attribute, vec float_vector knn_type='hnsw' "
        ."hnsw_similarity='l2' model_name='sentence-transformers/all-MiniLM-L6-v2' from='title')"
    );
});

afterEach(function () {
    DB::connection('manticore')->statement('DROP TABLE IF EXISTS it_embedded');
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
})->group('embeddings');

it('regenerates the vector when a numeric id document is partially replaced', function () {
    $db = DB::connection('manticore');
    $db->statement('DROP TABLE IF EXISTS it_embedded_int');
    $db->statement(
        "CREATE TABLE it_embedded_int (title text, tag string attribute, vec float_vector knn_type='hnsw' "
        ."hnsw_similarity='l2' model_name='sentence-transformers/all-MiniLM-L6-v2' from='title')"
    );

    try {
        $table = fn () => $db->table('it_embedded_int');
        $table()->insert([
            ['id' => 1, 'title' => 'yellow bag', 'tag' => 'x'],
            ['id' => 2, 'title' => 'yellow handbag', 'tag' => 'x'],
            ['id' => 3, 'title' => 'quantum physics lecture', 'tag' => 'x'],
        ]);

        expect($table()->knn('vec', 1, k: 5)->pluck('title')->all())->toBe(['yellow handbag', 'quantum physics lecture']);

        $table()->replaceWhereId(1, ['title' => 'quantum mechanics course']);

        $document = $table()->where('id', 1)->first();

        expect($document->title)->toBe('quantum mechanics course')
            ->and($document->tag)->toBe('x')
            ->and($table()->knn('vec', 1, k: 5)->pluck('title')->all())->toBe(['quantum physics lecture', 'yellow handbag']);
    } finally {
        $db->statement('DROP TABLE IF EXISTS it_embedded_int');
    }
})->group('embeddings');

it('finds documents by text with knnText(), embedded by the model of the column', function () {
    $table = fn () => DB::connection('manticore')->table('it_embedded');

    $table()->insert(['id' => '550e8400-e29b-41d4-a716-446655440000', 'title' => 'yellow handbag', 'tag' => 'x']);
    $table()->insert(['id' => '660e8400-e29b-41d4-a716-446655440001', 'title' => 'quantum physics lecture', 'tag' => 'x']);

    expect($table()->knnText('vec', "a lady's leather purse", k: 5)->pluck('title')->all())->toBe(['yellow handbag', 'quantum physics lecture'])
        ->and($table()->knnText('vec', 'a course on particle physics', k: 5)->where('tag', 'x')->pluck('title')->all())->toBe(['quantum physics lecture', 'yellow handbag']);
})->group('embeddings');

it('finds chunked documents by text with knnText() on a float_vector_array', function () {
    if (! supportsFloatVectorArray()) {
        $this->markTestSkipped('float_vector_array needs Manticore 29.9.0 or later.');
    }

    $db = DB::connection('manticore');
    $db->statement('DROP TABLE IF EXISTS it_embedded_chunks');
    $db->statement(
        "CREATE TABLE it_embedded_chunks (body text, passages float_vector_array knn_type='hnsw' hnsw_similarity='cosine' "
        ."model_name='sentence-transformers/all-MiniLM-L6-v2' from='body' chunk_strategy='sentence' max_tokens='16')"
    );

    try {
        $db->table('it_embedded_chunks')->insert([
            ['id' => 1, 'body' => 'The office kitchen has a new coffee machine. Rotate the TLS certificate every year.'],
            ['id' => 2, 'body' => 'Our football team won the regional cup. The final score was three to one.'],
        ]);

        expect($db->table('it_embedded_chunks')->knnText('passages', 'how do I rotate a certificate', k: 5)->pluck('id')->all())
            ->toBe([1, 2]);
    } finally {
        $db->statement('DROP TABLE IF EXISTS it_embedded_chunks');
    }
})->group('embeddings');

it('keeps the old vector on a partial REPLACE, which the manual does not cover', function () {
    // The manual's REPLACE page says nothing of auto-embeddings columns. A whole REPLACE embeds the
    // new text, while REPLACE ... SET, run by Buddy, carries the old vector over.
    $db = DB::connection('manticore');
    $db->statement('DROP TABLE IF EXISTS it_embedded_raw');
    $db->statement(
        "CREATE TABLE it_embedded_raw (title text, vec float_vector knn_type='hnsw' "
        ."hnsw_similarity='l2' model_name='sentence-transformers/all-MiniLM-L6-v2' from='title')"
    );

    try {
        $db->statement("INSERT INTO it_embedded_raw (id, title) VALUES (1, 'yellow bag'), (2, 'quantum physics lecture')");
        $nearest = fn () => array_column($db->select("SELECT id FROM it_embedded_raw WHERE knn(vec, 5, 'quantum mechanics course')"), 'id');

        $db->statement("REPLACE INTO it_embedded_raw SET title = 'quantum mechanics course' WHERE id = 1");
        expect($nearest())->toBe([2, 1]);

        $db->statement("REPLACE INTO it_embedded_raw (id, title) VALUES (1, 'quantum mechanics course')");
        expect($nearest())->toBe([1, 2]);
    } finally {
        $db->statement('DROP TABLE IF EXISTS it_embedded_raw');
    }
})->group('embeddings');
