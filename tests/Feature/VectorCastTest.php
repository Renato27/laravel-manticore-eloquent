<?php

use Illuminate\Database\Query\Expression;
use ManticoreEloquent\Eloquent\Casts\VectorCast;
use ManticoreEloquent\Eloquent\ManticoreModel;

class CastDoc extends ManticoreModel
{
    protected $table = 'docs_rt';

    protected $guarded = [];

    protected $casts = ['embedding' => VectorCast::class];
}

it('writes a vector as a bare tuple literal, never as a binding', function () {
    $model = new CastDoc(['embedding' => [0.1, 2, 0.35]]);

    $raw = $model->getAttributes()['embedding'];

    expect($raw)->toBeInstanceOf(Expression::class)
        ->and($raw->getValue(DB::connection('manticore')->getQueryGrammar()))->toBe('(0.1, 2.0, 0.35)');

    $insert = DB::connection('manticore')->table('docs_rt');
    expect($insert->getGrammar()->compileInsert($insert, [['embedding' => $raw]]))
        ->toContain('values ((0.1, 2.0, 0.35))');
});

it('refuses a list inside the vector, whose parts would be cast to 1.0', function () {
    expect(fn () => new CastDoc(['embedding' => [[0.1, 0.2], 0.3]]))->toThrow(InvalidArgumentException::class, 'Column [embedding] takes a list of numbers');
});

it('reads the vector back as floats, from the database and from a fresh set', function () {
    $model = new CastDoc;

    $model->setRawAttributes(['embedding' => '0.100000,2.000000,0.350000']);
    expect($model->embedding)->toBe([0.1, 2.0, 0.35]);

    $model->embedding = [0.5, 0.6];
    expect($model->embedding)->toBe([0.5, 0.6]);
});

it('passes a null or already decoded vector through on read', function () {
    $cast = new VectorCast;

    expect($cast->get(new CastDoc, 'embedding', null, []))->toBeNull()
        ->and($cast->get(new CastDoc, 'embedding', [0.5, 0.6], []))->toBe([0.5, 0.6]);
});

it('reads an empty vector as an empty array', function () {
    expect((new VectorCast)->get(new CastDoc, 'embedding', '', []))->toBe([]);
});

it('passes a value that is not an array through on write', function () {
    $raw = new Expression('(0.1, 0.2)');

    expect((new VectorCast)->set(new CastDoc, 'embedding', $raw, []))->toBe($raw)
        ->and((new VectorCast)->set(new CastDoc, 'embedding', null, []))->toBeNull();
});
