<?php

use Illuminate\Database\Query\Expression;
use ManticoreEloquent\Eloquent\Casts\VectorArrayCast;
use ManticoreEloquent\Eloquent\ManticoreModel;

class CastChunkedDoc extends ManticoreModel
{
    protected $table = 'docs_rt';

    protected $guarded = [];

    protected $casts = ['chunks' => VectorArrayCast::class];
}

it('writes an array of vectors as a bare literal, never as a binding', function () {
    $raw = (new CastChunkedDoc(['chunks' => [[0.1, 2], [0.35, -1]]]))->getAttributes()['chunks'];

    expect($raw)->toBeInstanceOf(Expression::class)
        ->and($raw->getValue(DB::connection('manticore')->getQueryGrammar()))->toBe('[[0.1, 2.0], [0.35, -1.0]]');
});

it('writes no vectors as an empty array', function () {
    $raw = (new CastChunkedDoc(['chunks' => []]))->getAttributes()['chunks'];

    expect($raw->getValue(DB::connection('manticore')->getQueryGrammar()))->toBe('[]');
});

it('refuses a flat list, which the server cannot split into vectors', function () {
    expect(fn () => new CastChunkedDoc(['chunks' => [0.1, 0.2]]))->toThrow(InvalidArgumentException::class, 'list of vectors');
});

it('reads the vectors back as floats, from the database and from a fresh set', function () {
    $model = new CastChunkedDoc;

    $model->setRawAttributes(['chunks' => '[[0.100000,2.000000],[0.350000,-1.000000]]']);
    expect($model->chunks)->toBe([[0.1, 2.0], [0.35, -1.0]]);

    $model->chunks = [[0.5, 0.6]];
    expect($model->chunks)->toBe([[0.5, 0.6]]);
});

it('reads no vectors as an empty array', function (string $value) {
    expect((new VectorArrayCast)->get(new CastChunkedDoc, 'chunks', $value, []))->toBe([]);
})->with(['[]', '']);

it('passes a null or already decoded value through on read', function () {
    $cast = new VectorArrayCast;

    expect($cast->get(new CastChunkedDoc, 'chunks', null, []))->toBeNull()
        ->and($cast->get(new CastChunkedDoc, 'chunks', [[0.5, 0.6]], []))->toBe([[0.5, 0.6]]);
});

it('passes a value that is not an array through on write', function () {
    $raw = new Expression('[[0.1, 0.2]]');

    expect((new VectorArrayCast)->set(new CastChunkedDoc, 'chunks', $raw, []))->toBe($raw)
        ->and((new VectorArrayCast)->set(new CastChunkedDoc, 'chunks', null, []))->toBeNull();
});
