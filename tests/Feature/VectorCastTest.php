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

it('reads the vector back as floats, from the database and from a fresh set', function () {
    $model = new CastDoc;

    $model->setRawAttributes(['embedding' => '0.100000,2.000000,0.350000']);
    expect($model->embedding)->toBe([0.1, 2.0, 0.35]);

    $model->embedding = [0.5, 0.6];
    expect($model->embedding)->toBe([0.5, 0.6]);
});
