<?php

use ManticoreEloquent\Database\ManticoreConnection;
use ManticoreEloquent\Database\Query\Grammars\ManticoreQueryGrammar;
use ManticoreEloquent\Database\Query\ManticoreQueryBuilder;
use ManticoreEloquent\Database\Query\Processors\ManticoreProcessor;

function processJoinedRows(array $columns, array $row): array
{
    $connection = Mockery::mock(ManticoreConnection::class);
    $connection->shouldReceive('getTablePrefix')->andReturn('');

    $query = (new ManticoreQueryBuilder($connection, new ManticoreQueryGrammar($connection), new ManticoreProcessor))
        ->from('a')->join('b', 'a.id', '=', 'b.x');
    $query->columns = $columns;

    return (array) (new ManticoreProcessor)->processSelect($query, [(object) $row])[0];
}

afterEach(fn () => Mockery::close());

it('names a joined column by its last part, as MySQL would', function () {
    expect(processJoinedRows(['a.id', 'b.note'], ['a.id' => 1, 'b.note' => 'x']))->toBe(['id' => 1, 'note' => 'x']);
});

it('keeps the left table when both have the column', function () {
    expect(processJoinedRows(['*'], ['id' => 1, 'b.id' => 2, 'b.x' => 3]))->toBe(['id' => 1, 'x' => 3]);
});

it('leaves the other table out when only the left one was asked for', function () {
    expect(processJoinedRows(['a.*', 'b.x as pivot_x', 'b.y'], ['id' => 1, 'b.id' => 2, 'b.x' => 3, 'b.y' => 4, 'pivot_x' => 3]))
        ->toBe(['id' => 1, 'y' => 4, 'pivot_x' => 3]);
});

it('leaves a query without a join alone', function () {
    $connection = Mockery::mock(ManticoreConnection::class);
    $connection->shouldReceive('getTablePrefix')->andReturn('');

    $query = (new ManticoreQueryBuilder($connection, new ManticoreQueryGrammar($connection), new ManticoreProcessor))->from('a');
    $row = (object) ['j.a' => 1];

    expect((new ManticoreProcessor)->processSelect($query, [$row])[0])->toBe($row);
});
