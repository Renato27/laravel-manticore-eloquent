<?php

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use ManticoreEloquent\Concerns\HasManticore;
use ManticoreEloquent\Eloquent\ManticoreModel;

class UuidKeyDoc extends ManticoreModel
{
    protected $table = 'docs_rt';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];
}

class HasUuidsDoc extends ManticoreModel
{
    use HasUuids;

    protected $table = 'docs_rt';

    protected $guarded = [];
}

class IntKeyDoc extends ManticoreModel
{
    protected $table = 'docs_rt';
}

class GatewayUuidDoc extends Model
{
    use HasManticore;

    protected $table = 'docs_rt';
}

it('compiles knn() by uuid through the HasManticore gateway', function () {
    expect(GatewayUuidDoc::manticore()->knn('embedding', '550e8400-e29b-41d4-a716-446655440000', k: 10)->toBase()->toSql())
        ->toContain('knn(embedding, 10, 550e8400-e29b-41d4-a716-446655440000)');
});

it('lets a model declare a uuid key', function () {
    $model = new UuidKeyDoc;

    expect($model->getKeyType())->toBe('string')
        ->and($model->getIncrementing())->toBeFalse();
});

it('keeps a uuid key intact instead of casting it to an integer', function () {
    $model = new UuidKeyDoc(['id' => '550e8400-e29b-41d4-a716-446655440000']);

    expect($model->getKey())->toBe('550e8400-e29b-41d4-a716-446655440000');
});

it('filters a uuid key with a bound string', function () {
    $query = UuidKeyDoc::query()->whereKey('550e8400-e29b-41d4-a716-446655440000')->toBase();

    expect($query->toSql())->toBe(unlimited('select * from `docs_rt` where `id` = ?'))
        ->and($query->getBindings())->toBe(['550e8400-e29b-41d4-a716-446655440000']);
});

it('filters several uuid keys through bindings rather than inlined integers', function () {
    $query = UuidKeyDoc::query()->whereKey([
        '550e8400-e29b-41d4-a716-446655440000',
        '660e8400-e29b-41d4-a716-446655440001',
    ])->toBase();

    expect($query->toSql())->toBe(unlimited('select * from `docs_rt` where `id` in (?, ?)'));
});

it('works with the HasUuids trait from Laravel', function () {
    $model = new HasUuidsDoc;

    expect($model->getKeyType())->toBe('string')
        ->and($model->getIncrementing())->toBeFalse()
        ->and($model->newUniqueId())->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/');
});

it('filters an integer key without qualifying it either', function () {
    expect(IntKeyDoc::query()->whereKey(7)->toBase()->toSql())
        ->toBe(unlimited('select * from `docs_rt` where `id` = ?'));
});

it('keeps the integer key as the default', function () {
    $model = new IntKeyDoc;

    expect($model->getKeyType())->toBe('int')
        ->and($model->getIncrementing())->toBeTrue();
});
