<?php

use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;
use ManticoreEloquent\Eloquent\Casts\MvaCast;
use ManticoreEloquent\Eloquent\ManticoreModel;

class MvaCastDoc extends ManticoreModel
{
    protected $table = 'docs_rt';
    protected $guarded = [];
    protected $casts = ['tag_ids' => MvaCast::class];
}

function mvaRaw(MvaCastDoc $model): string
{
    return $model->getAttributes()['tag_ids']->getValue(DB::connection('manticore')->getQueryGrammar());
}

it('writes a list as a tuple literal', function () {
    expect(mvaRaw(new MvaCastDoc(['tag_ids' => [1, '2']])))->toBe('(1, 2)')
        ->and(mvaRaw(new MvaCastDoc(['tag_ids' => []])))->toBe('()');
});

it('forces every value to an integer, so nothing else reaches the SQL', function () {
    expect(mvaRaw(new MvaCastDoc(['tag_ids' => ['1) or (1=1', 2]])))->toBe('(1, 2)');
});

it('reads the comma separated form as integers', function () {
    $doc = (new MvaCastDoc)->newFromBuilder(['tag_ids' => '3,4']);

    expect($doc->tag_ids)->toBe([3, 4]);
});

it('reads an empty value as an empty list', function (mixed $raw) {
    expect((new MvaCastDoc)->newFromBuilder(['tag_ids' => $raw])->tag_ids)->toBe([]);
})->with(['an empty string' => '', 'null' => null]);

it('reads back what it was given before saving', function () {
    expect((new MvaCastDoc(['tag_ids' => [5, 6]]))->tag_ids)->toBe([5, 6])
        ->and((new MvaCastDoc(['tag_ids' => []]))->tag_ids)->toBe([]);
});

it('stores a value that is not a list as it is', function () {
    expect((new MvaCastDoc(['tag_ids' => new Expression('(9)')]))->getAttributes()['tag_ids'])
        ->toBeInstanceOf(Expression::class);
});
