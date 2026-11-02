<?php

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\QueryException;
use ManticoreEloquent\Database\ManticoreConnection;
use ManticoreEloquent\Database\Query\Grammars\ManticoreQueryGrammar;
use ManticoreEloquent\Database\Query\ManticoreQueryBuilder;
use ManticoreEloquent\Database\Query\Processors\ManticoreProcessor;

function mockManticoreConnection()
{
    $connection = Mockery::mock(ManticoreConnection::class);
    $connection->shouldReceive('columnTypes')->andReturn([])->byDefault();

    return $connection;
}

function manticoreQuery(string $table = 'companies'): ManticoreQueryBuilder
{
    $connection = mockManticoreConnection();
    $connection->shouldReceive('getTablePrefix')->andReturn('');

    return (new ManticoreQueryBuilder(
        $connection,
        new ManticoreQueryGrammar($connection),
        new ManticoreProcessor
    ))->from($table);
}

afterEach(fn () => Mockery::close());

it('compiles a basic select like Eloquent', function () {
    $q = manticoreQuery()->where('country', 'BR');

    expect($q->toSql())->toBe(unlimited('select * from `companies` where `country` = ?'));
    expect($q->getBindings())->toBe(['BR']);
});

it('compiles a full-text MATCH as a bound parameter', function () {
    $q = manticoreQuery()->match('fintech')->where('country', 'BR');

    expect($q->toSql())->toContain('MATCH(?)');
    expect($q->getBindings())->toBe(['fintech', 'BR']);
});

it('prefixes the field when match() targets a specific field', function () {
    $q = manticoreQuery()->match('startup', 'title');

    expect($q->getBindings())->toBe(['@title startup']);
});

it('appends an OPTION clause for explicit options', function () {
    $q = manticoreQuery()->where('status', 'active')->option('ranker', 'sph04');

    expect($q->toSql())->toContain('OPTION ranker=sph04');
});

it('emits maxMatches() as a numeric option', function () {
    expect(manticoreQuery()->maxMatches(5000)->toSql())->toContain('OPTION max_matches=5000');
});

it('auto-raises max_matches when paging beyond the default cap', function () {
    expect(manticoreQuery()->offset(1500)->limit(50)->toSql())->toContain('max_matches=1550');
});

it('does not force max_matches for shallow paging', function () {
    expect(manticoreQuery()->limit(20)->toSql())->not->toContain('max_matches');
});

it('never emits row-level locks', function () {
    expect(manticoreQuery()->where('id', 1)->lock()->toSql())->not->toContain('for update');
});

it('appends a FACET clause', function () {
    expect(manticoreQuery()->match('cloud')->facet('country')->toSql())->toContain('FACET country');
});

it('compiles a knn() predicate with inlined float literals', function () {
    $q = manticoreQuery('docs')->knn('embedding', [0.1, 0.2, 0.3], 5);

    expect($q->toSql())->toContain('where knn(`embedding`, 5, (0.1, 0.2, 0.3))');
    expect($q->getBindings())->toBe([]); // vector is inlined, not bound
});

it('casts integer vector components to float literals', function () {
    expect(manticoreQuery('docs')->knn('embedding', [1, 2], 5)->toSql())
        ->toContain('knn(`embedding`, 5, (1.0, 2.0))');
});

it('adds the ef argument to knn() when provided', function () {
    $sql = manticoreQuery('docs')->knn('embedding', [0.1, 0.2], 10, 2000)->toSql();

    expect($sql)->toContain('knn(`embedding`, 10, (0.1, 0.2), {ef=2000})');
});

it('renders any knn option the caller passes, without a list of known names', function () {
    $sql = manticoreQuery('docs')->knn('embedding', [0.1], 10, [
        'ef' => 2000, 'rescore' => true, 'oversampling' => 3.0, 'some_future_option' => 'auto',
    ])->toSql();

    expect($sql)->toContain('knn(`embedding`, 10, (0.1), {ef=2000, rescore=1, oversampling=3.0, some_future_option=auto})');
});

it('combines knn() with regular filters, binding only the regular ones', function () {
    $q = manticoreQuery('docs')->knn('embedding', [0.5, 0.6], 3)->where('published', true);

    expect($q->toSql())->toContain('knn(`embedding`, 3, (0.5, 0.6))');
    expect($q->getBindings())->toBe([true]);
});

it('adds a HIGHLIGHT() column without dropping the * select', function () {
    $q = manticoreQuery()->match('cloud')->highlight();

    expect($q->toSql())->toStartWith('select *, HIGHLIGHT() as `highlight`');
    expect($q->getBindings())->toBe(['cloud']);
});

it('scopes HIGHLIGHT() to a field', function () {
    expect(manticoreQuery()->highlight('title')->toSql())->toContain("HIGHLIGHT({}, 'title')");
});

it('renders HIGHLIGHT() options and an aliased column', function () {
    $sql = manticoreQuery()->highlight('body', ['before_match' => '<b>', 'limit' => 10], 'snippet')->toSql();

    expect($sql)->toContain("HIGHLIGHT({before_match='<b>', limit=10}, 'body') as `snippet`");
});

it('renders boolean HIGHLIGHT() options as 0/1', function () {
    $sql = manticoreQuery()->highlight(null, ['html_strip_mode' => true, 'force_all_words' => false])->toSql();

    expect($sql)->toContain('HIGHLIGHT({html_strip_mode=1, force_all_words=0})');
});

it('compiles whereIn natively', function () {
    $q = manticoreQuery()->whereIn('id', [1, 2, 3]);

    expect($q->toSql())->toBe(unlimited('select * from `companies` where `id` in (?, ?, ?)'));
    expect($q->getBindings())->toBe([1, 2, 3]);
});

it('compiles REPLACE INTO for full-document writes', function () {
    $query = manticoreQuery('articles')->from('articles');

    $sql = $query->getGrammar()->compileReplace(
        $query,
        [['id' => 1, 'title' => 'Hello']]
    );

    expect($sql)->toStartWith('replace into `articles`');
    expect($sql)->toContain('(`id`, `title`)');
});

it('renders boolean options as 0/1', function () {
    expect(manticoreQuery()->option('cutoff', true)->toSql())->toContain('OPTION cutoff=1');
    expect(manticoreQuery()->option('cutoff', false)->toSql())->toContain('OPTION cutoff=0');
});

it('renders a list option as a parenthesised tuple', function () {
    $sql = manticoreQuery()->option('field_weights', [10, 3])->toSql();

    expect($sql)->toContain('OPTION field_weights=(10,3)');
});

it('renders an associative option as key=value pairs', function () {
    $sql = manticoreQuery()->option('field_weights', ['title' => 10, 'body' => 3])->toSql();

    expect($sql)->toContain('OPTION field_weights=(title=10,body=3)');
});

it('combines several options into a single OPTION clause', function () {
    $sql = manticoreQuery()->option('ranker', 'sph04')->maxMatches(2000)->toSql();

    expect($sql)->toContain('OPTION ranker=sph04, max_matches=2000');
});

it('asks for every row when the query has no limit', function () {
    expect(manticoreQuery('docs')->toSql())
        ->toBe('select * from `docs` limit 2147483646 OPTION max_matches=2147483646');
});

it('reaches an offset without a limit', function () {
    expect(manticoreQuery('docs')->offset(5)->toSql())
        ->toBe('select * from `docs` limit 2147483646 offset 5 OPTION max_matches=2147483646');
});

it('leaves an aggregate without the unlimited clause', function () {
    $connection = mockManticoreConnection();
    $connection->shouldReceive('getTablePrefix')->andReturn('');
    $connection->shouldReceive('select')->once()
        ->withArgs(fn (string $sql) => str_starts_with($sql, 'select count(*)') && ! str_contains($sql, 'limit'))
        ->andReturn([(object) ['aggregate' => 3]]);

    $query = new ManticoreQueryBuilder($connection, new ManticoreQueryGrammar($connection), new ManticoreProcessor);

    expect($query->from('docs')->count())->toBe(3);
});

it('lets an explicit max_matches win over the unlimited clause', function () {
    expect(manticoreQuery('docs')->maxMatches(50)->toSql())
        ->toBe('select * from `docs` limit 2147483646 OPTION max_matches=50');
});

it('treats an empty replace() as a no-op', function () {
    expect(manticoreQuery()->replace([]))->toBeTrue();
});

it('compiles a knn() predicate against a document id', function () {
    $q = manticoreQuery('docs')->knn('embedding', 123, 5);

    expect($q->toSql())->toContain('where knn(embedding, 5, 123)');
    expect($q->getBindings())->toBe([]);
});

it('adds the ef argument to a document-id knn()', function () {
    expect(manticoreQuery('docs')->knn('embedding', 123, 5, 2000)->toSql())
        ->toContain('knn(embedding, 5, 123, {ef=2000})');
});

it('rejects a non-identifier column in the document-id form', function () {
    manticoreQuery('docs')->knn('embedding); drop table docs; --', 1)->toSql();
})->throws(InvalidArgumentException::class);

it('rejects a knn option value carrying a SQL fragment', function ($value) {
    manticoreQuery('docs')->knn('embedding', 1, 10, ['ef' => $value])->toSql();
})->throws(InvalidArgumentException::class)->with([
    '2000}) OR 1=1 -- ',
    "1', drop table docs, '",
    "}) union select * from docs where match('')",
    [['2000', 'OR 1=1']],
]);

it('rejects a knn option name carrying a SQL fragment', function () {
    manticoreQuery('docs')->knn('embedding', 1, 10, ['ef=1, rescore' => 1])->toSql();
})->throws(InvalidArgumentException::class);

it('lets DB::raw() through as the deliberate escape hatch', function () {
    expect(manticoreQuery('docs')->knn('embedding', 1, 10, ['ef' => new Expression('2000')])->toSql())
        ->toContain('knn(embedding, 10, 1, {ef=2000})');
});

it('treats a null knn option as unset rather than zero', function () {
    $sql = manticoreQuery('docs')->knn('embedding', [0.1], 10, ['ef' => null, 'rescore' => true])->toSql();

    expect($sql)->toContain('knn(`embedding`, 10, (0.1), {rescore=1})');
});

it('passes a self-contained quoted literal through, the way manticore wants idf and comment', function ($value) {
    expect(manticoreQuery('docs')->option('idf', $value)->toSql())->toContain("OPTION idf={$value}");
})->with(["'plain,tfidf_unnormalized'", "'lib.so,name,opt=1'", "''"]);

it('rejects a quoted option value that closes its own literal', function ($value) {
    manticoreQuery('docs')->option('comment', $value)->toSql();
})->throws(InvalidArgumentException::class)->with([
    "'a', max_matches=1, comment='b'",
    "a' OR 1=1 -- ",
    "'a\\'",
]);

it('compiles a partial REPLACE ... SET ... WHERE id', function () {
    $query = manticoreQuery('t1_docs');

    $sql = $query->getGrammar()->compileReplaceSet($query, ['title' => 'Hello', 'price' => 10]);

    expect($sql)->toBe('replace into `t1_docs` set `title` = ?, `price` = ? where `id` = ?');
});

it('inlines Expression values in a partial REPLACE instead of binding them', function () {
    $query = manticoreQuery('t1_docs');

    $sql = $query->getGrammar()->compileReplaceSet($query, [
        'title' => 'Hello',
        'embedding' => new Expression('(0.1, 0.2)'),
    ]);

    expect($sql)->toBe('replace into `t1_docs` set `title` = ?, `embedding` = (0.1, 0.2) where `id` = ?');
});

it('binds the values and then the id for a partial REPLACE', function () {
    $connection = mockManticoreConnection();
    $connection->shouldReceive('getTablePrefix')->andReturn('');
    $connection->shouldReceive('insert')
        ->once()
        ->with(
            'replace into `t1_docs` set `title` = ?, `embedding` = (0.1, 0.2), `price` = ? where `id` = ?',
            ['Hello', 10, 42]
        )
        ->andReturn(true);

    $query = (new ManticoreQueryBuilder(
        $connection,
        new ManticoreQueryGrammar($connection),
        new ManticoreProcessor
    ))->from('t1_docs');

    expect($query->replaceWhereId(42, [
        'title' => 'Hello',
        'embedding' => new Expression('(0.1, 0.2)'),
        'price' => 10,
    ]))->toBeTrue();
});

it('treats an empty replaceWhereId() as a no-op', function () {
    expect(manticoreQuery()->replaceWhereId(1, []))->toBeTrue();
});

it('rejects a non-positive id in replaceWhereId()', function (int $id) {
    manticoreQuery()->replaceWhereId($id, ['title' => 'x']);
})->with([0, -5])->throws(InvalidArgumentException::class);

/**
 * A builder whose connection expects exactly one partial REPLACE with the given id binding.
 */
function expectPartialReplaceWithId(int|string $id): ManticoreQueryBuilder
{
    $connection = mockManticoreConnection();
    $connection->shouldReceive('getTablePrefix')->andReturn('');
    $connection->shouldReceive('insert')
        ->once()
        ->with('replace into `articles` set `tag` = ? where `id` = ?', ['beta', $id])
        ->andReturn(true);

    return (new ManticoreQueryBuilder(
        $connection,
        new ManticoreQueryGrammar($connection),
        new ManticoreProcessor
    ))->from('articles');
}

it('binds a uuid document id in replaceWhereId()', function () {
    expect(
        expectPartialReplaceWithId('550e8400-e29b-41d4-a716-446655440000')
            ->replaceWhereId('550e8400-e29b-41d4-a716-446655440000', ['tag' => 'beta'])
    )->toBeTrue();
});

it('accepts a uuid in any case in replaceWhereId()', function () {
    expect(
        expectPartialReplaceWithId('550E8400-E29B-41D4-A716-446655440000')
            ->replaceWhereId('550E8400-E29B-41D4-A716-446655440000', ['tag' => 'beta'])
    )->toBeTrue();
});

it('binds a numeric string id as an integer, which is the only form Buddy accepts', function () {
    expect(expectPartialReplaceWithId(42)->replaceWhereId('42', ['tag' => 'beta']))->toBeTrue();
});

/**
 * Ids Manticore refuses, per its own validation: versions outside 1-8, variants outside
 * 8/9/a/b and the nil UUID are all rejected by the server.
 */
dataset('invalid document ids', [
    'not a uuid' => 'not-a-uuid',
    'empty' => '',
    'zero as a string' => '0',
    'negative string' => '-5',
    'beyond PHP_INT_MAX' => '99999999999999999999',
    'version 0' => '550e8400-e29b-01d4-a716-446655440000',
    'version 9' => '550e8400-e29b-91d4-a716-446655440000',
    'variant c' => '550e8400-e29b-41d4-c716-446655440000',
    'nil uuid' => '00000000-0000-0000-0000-000000000000',
    'no dashes' => '550e8400e29b41d4a716446655440000',
    'trailing newline' => "550e8400-e29b-41d4-a716-446655440000\n",
    'sql fragment' => "550e8400-e29b-41d4-a716-446655440000'); drop table docs; --",
]);

it('rejects an invalid string id in replaceWhereId()', function (string $id) {
    manticoreQuery()->replaceWhereId($id, ['title' => 'x']);
})->with('invalid document ids')->throws(InvalidArgumentException::class);

it('validates the id even when replaceWhereId() has nothing to write', function () {
    manticoreQuery()->replaceWhereId('not-a-uuid', []);
})->throws(InvalidArgumentException::class);

it('compiles a uuid document id into knn() without quotes', function () {
    $q = manticoreQuery('docs')->knn('embedding', '550e8400-e29b-41d4-a716-446655440000', k: 10);

    expect($q->toSql())->toContain('where knn(embedding, 10, 550e8400-e29b-41d4-a716-446655440000)');
    expect($q->getBindings())->toBe([]);
});

it('never quotes a uuid in knn(), since Manticore reads a quoted string as text to embed', function () {
    expect(manticoreQuery('docs')->knn('embedding', '550e8400-e29b-41d4-a716-446655440000')->toSql())
        ->not->toContain("'");
});

it('keeps the option block after a uuid document id in knn()', function () {
    expect(manticoreQuery('docs')->knn('embedding', '550e8400-e29b-41d4-a716-446655440000', 5, 2000)->toSql())
        ->toContain('knn(embedding, 5, 550e8400-e29b-41d4-a716-446655440000, {ef=2000})');
});

it('still compiles an integer document id in knn()', function () {
    expect(manticoreQuery('docs')->knn('embedding', 42, k: 10)->toSql())
        ->toContain('knn(embedding, 10, 42)');
});

it('compiles a numeric string document id in knn() as an integer', function () {
    expect(manticoreQuery('docs')->knn('embedding', '42', k: 10)->toSql())
        ->toContain('knn(embedding, 10, 42)');
});

it('rejects an invalid string document id in knn()', function (string $id) {
    manticoreQuery('docs')->knn('embedding', $id)->toSql();
})->with('invalid document ids')->throws(InvalidArgumentException::class);

it('rejects a non-identifier column when knn() gets a uuid', function () {
    manticoreQuery('docs')->knn('embedding); drop table docs; --', '550e8400-e29b-41d4-a716-446655440000')->toSql();
})->throws(InvalidArgumentException::class);

/**
 * A builder whose connection rejects the native partial REPLACE with the given message.
 */
function queryRejectingPartialReplace(string $message): ManticoreQueryBuilder
{
    $connection = mockManticoreConnection();
    $connection->shouldReceive('getTablePrefix')->andReturn('');
    $connection->shouldReceive('getName')->andReturn('manticore');
    $connection->shouldReceive('insert')
        ->once()
        ->andThrow(new QueryException('manticore', 'replace into', [], new Exception($message)));

    return (new ManticoreQueryBuilder(
        $connection,
        new ManticoreQueryGrammar($connection),
        new ManticoreProcessor
    ))->from('articles');
}

it('does not emulate a partial replace for an integer id', function () {
    queryRejectingPartialReplace("P01: syntax error, unexpected SET, expecting VALUES near 'SET'")
        ->replaceWhereId(42, ['tag' => 'beta']);
})->throws(QueryException::class, 'unexpected SET');

it('does not emulate a partial replace by uuid when the server failed for another reason', function () {
    queryRejectingPartialReplace('table articles: no such attribute')
        ->replaceWhereId('550e8400-e29b-41d4-a716-446655440000', ['tag' => 'beta']);
})->throws(QueryException::class, 'no such attribute');

it('leaves an auto-embeddings column out of the emulated partial replace, so it is regenerated', function () {
    $uuid = '550e8400-e29b-41d4-a716-446655440000';

    $connection = mockManticoreConnection();
    $connection->shouldReceive('getTablePrefix')->andReturn('');
    $connection->shouldReceive('getName')->andReturn('manticore');
    $connection->shouldReceive('columnTypes')->with('articles')
        ->andReturn(['id' => 'uuid', 'title' => 'text', 'tag' => 'string', 'vec' => 'float_vector', 'other' => 'float_vector']);
    $connection->shouldReceive('insert')
        ->once()
        ->with('replace into `articles` set `title` = ? where `id` = ?', ['new text', $uuid])
        ->andThrow(new QueryException('manticore', 'replace into', [], new Exception("unexpected SET, expecting VALUES near 'SET'")));
    $connection->shouldReceive('select')->with('DESC articles')->andReturn([
        (object) ['Field' => 'id', 'Type' => 'uuid', 'Properties' => ''],
        (object) ['Field' => 'title', 'Type' => 'text', 'Properties' => 'indexed stored'],
        (object) ['Field' => 'tag', 'Type' => 'string', 'Properties' => ''],
        (object) ['Field' => 'vec', 'Type' => 'float_vector', 'Properties' => 'knn'],
        (object) ['Field' => 'other', 'Type' => 'float_vector', 'Properties' => 'knn'],
    ]);
    $connection->shouldReceive('select')->with('SHOW CREATE TABLE articles')->andReturn([
        (object) ['Table' => 'articles', 'Create Table' => "CREATE TABLE articles (\nid uuid,\ntitle text,\ntag string attribute,\nvec float_vector knn_type='hnsw' hnsw_similarity='L2' model_name='sentence-transformers/all-MiniLM-L6-v2' from='title' api_timeout='10',\nother float_vector knn_type='hnsw' knn_dims='2' hnsw_similarity='L2'\n)"],
    ]);
    $connection->shouldReceive('select')
        ->withArgs(fn (string $sql, array $bindings = []) => $sql === 'select * from `articles` where `id` = ? limit 1' && $bindings === [$uuid])
        ->andReturn([(object) ['title' => 'old text', 'id' => $uuid, 'tag' => 'kept', 'vec' => '0.5,0.6', 'other' => '0.1,0.2']]);
    $connection->shouldReceive('insert')
        ->once()
        ->with('replace into `articles` (`title`, `id`, `tag`, `other`) values (?, ?, ?, (0.1, 0.2))', ['new text', $uuid, 'kept'])
        ->andReturn(true);

    $query = (new ManticoreQueryBuilder(
        $connection,
        new ManticoreQueryGrammar($connection),
        new ManticoreProcessor
    ))->from('articles');

    expect($query->replaceWhereId($uuid, ['title' => 'new text']))->toBeTrue();
});

it('renders a float highlight option as a bare number', function () {
    expect(manticoreQuery('docs')->highlight('title', ['limit_words' => 1.5])->toSql())
        ->toContain("HIGHLIGHT({limit_words=1.5}, 'title')");
});

it('checks existence by selecting one row, since Manticore has no select exists()', function (array $rows, bool $expected) {
    $connection = mockManticoreConnection();
    $connection->shouldReceive('getTablePrefix')->andReturn('');
    $connection->shouldReceive('select')
        ->once()
        ->withArgs(fn (string $sql, array $bindings = []) => $sql === 'select * from `docs` where `tag` = ? limit 1' && $bindings === ['a'])
        ->andReturn($rows);

    $query = (new ManticoreQueryBuilder(
        $connection,
        new ManticoreQueryGrammar($connection),
        new ManticoreProcessor
    ))->from('docs')->where('tag', 'a');

    expect($query->exists())->toBe($expected);
    expect($query->toSql())->toBe(unlimited('select * from `docs` where `tag` = ?'));
})->with([
    'a row' => [[(object) ['id' => 1]], true],
    'no rows' => [[], false],
]);

it('starts keyset paging without a null filter, which Manticore rejects on an id', function () {
    expect(manticoreQuery('docs')->forPageAfterId(2, null)->toSql())
        ->toBe('select * from `docs` order by `id` asc limit 2');

    expect(manticoreQuery('docs')->forPageBeforeId(2, null)->toSql())
        ->toBe('select * from `docs` order by `id` desc limit 2');
});

it('continues keyset paging from the last id', function () {
    $after = manticoreQuery('docs')->orderBy('id', 'desc')->forPageAfterId(2, 7);
    expect($after->toSql())->toBe('select * from `docs` where `id` > ? order by `id` asc limit 2');
    expect($after->getBindings())->toBe([7]);

    $before = manticoreQuery('docs')->orderBy('id')->forPageBeforeId(2, 7);
    expect($before->toSql())->toBe('select * from `docs` where `id` < ? order by `id` desc limit 2');
    expect($before->getBindings())->toBe([7]);
});

it('leaves knn() by a numeric string id to the server, without reading the vector first', function () {
    $connection = mockManticoreConnection();
    $connection->shouldReceive('getTablePrefix')->andReturn('');
    $connection->shouldReceive('select')
        ->once()
        ->withArgs(fn (string $sql) => $sql === unlimited('select * from `docs` where knn(embedding, 10, 42)'))
        ->andReturn([]);

    (new ManticoreQueryBuilder(
        $connection,
        new ManticoreQueryGrammar($connection),
        new ManticoreProcessor
    ))->from('docs')->knn('embedding', '42', k: 10)->get();
});

it('leaves the table out of updated columns, since Manticore rejects qualified columns', function () {
    $connection = mockManticoreConnection();
    $connection->shouldReceive('getTablePrefix')->andReturn('');
    $connection->shouldReceive('update')
        ->once()
        ->with('update `docs` set `views` = ?, `updated_at` = ? where `id` = ?', [2, 1700000000, 1])
        ->andReturn(1);

    (new ManticoreQueryBuilder(
        $connection,
        new ManticoreQueryGrammar($connection),
        new ManticoreProcessor
    ))->from('docs')->where('id', 1)->update(['views' => 2, 'docs.updated_at' => 1700000000]);
});

it('keeps a json path intact when compiling updated columns', function () {
    $query = manticoreQuery('docs');

    expect($query->getGrammar()->compileUpdate($query, ['meta->a' => 1]))
        ->toContain('set meta.a = ?');
});

it('leaves its own table out of filters, since Manticore rejects qualified columns', function () {
    $q = manticoreQuery('docs')
        ->where('docs.tag', 'a')
        ->whereIn('docs.views', [1, 2])
        ->whereIntegerInRaw('docs.id', [3]);

    expect($q->toSql())->toBe(unlimited('select * from `docs` where `tag` = ? and `views` in (?, ?) and `id` in (3)'));
    expect($q->getBindings())->toBe(['a', 1, 2]);
});

it('leaves the table out inside nested filters', function () {
    $q = manticoreQuery('docs')->where(fn ($query) => $query->where('docs.tag', 'a')->orWhere('docs.tag', 'b'));

    expect($q->toSql())->toBe(unlimited('select * from `docs` where (`tag` = ? or `tag` = ?)'));
});

it('recognises an aliased table', function () {
    expect(manticoreQuery('docs as d')->where('d.tag', 'a')->toSql())
        ->toBe(unlimited('select * from `docs` as `d` where `tag` = ?'));
});

it('keeps a qualifier naming another table, which is an error worth seeing', function () {
    expect(manticoreQuery('docs')->where('other.tag', 'a')->toSql())
        ->toBe(unlimited('select * from `docs` where `other`.`tag` = ?'));
});

it('leaves a query with a join alone', function () {
    expect(manticoreQuery('docs')->join('b', 'docs.id', '=', 'b.doc_id')->where('docs.tag', 'a')->whereNotNull('docs.x')->toSql())
        ->toBe(unlimited('select * from `docs` inner join `b` on `docs`.`id` = `b`.`doc_id` where `docs`.`tag` = ? and `docs`.`x` is not null'));
});

it('keeps a whereNotNull() written without a qualifier', function () {
    expect(manticoreQuery('docs')->whereNotNull('meta')->toSql())
        ->toBe(unlimited('select * from `docs` where `meta` is not null'));
});

it('keeps a qualified whereNull()', function () {
    expect(manticoreQuery('docs')->whereNull('docs.meta')->toSql())
        ->toBe(unlimited('select * from `docs` where `meta` is null'));
});

it('leaves the table out of update and delete filters', function () {
    $query = manticoreQuery('docs')->where('docs.id', 1);

    expect($query->getGrammar()->compileUpdate($query, ['views' => 2]))
        ->toBe('update `docs` set `views` = ? where `id` = ?');
    expect($query->getGrammar()->compileDelete($query))
        ->toBe('delete from `docs` where `id` = ?');
});

it('leaves raw filters and raw tables alone', function () {
    expect(manticoreQuery('docs')->whereRaw('docs.a = 1')->toSql())
        ->toBe(unlimited('select * from `docs` where docs.a = 1'));

    expect(manticoreQuery('docs')->where(new Expression('docs.a'), 1)->toSql())
        ->toBe(unlimited('select * from `docs` where docs.a = ?'));

    expect(manticoreQuery('docs')->fromRaw('docs')->where('docs.a', 1)->toSql())
        ->toBe(unlimited('select * from docs where `docs`.`a` = ?'));
});

it('does not change the query while compiling it', function () {
    $query = manticoreQuery('docs')->where('docs.tag', 'a')->whereNotNull('docs.tag');

    $query->toSql();

    expect($query->wheres)->toHaveCount(2);
    expect($query->wheres[0]['column'])->toBe('docs.tag');
});

function typedQuery(): ManticoreQueryBuilder
{
    $connection = mockManticoreConnection();
    $connection->shouldReceive('getTablePrefix')->andReturn('');
    $connection->shouldReceive('columnTypes')->with('docs')->andReturn([
        'id' => 'bigint', 'views' => 'uint', 'score' => 'float', 'slug' => 'string',
        'seen_at' => 'timestamp', 'on' => 'bool', 'ref' => 'uuid', 'meta' => 'json',
    ]);

    return (new ManticoreQueryBuilder($connection, new ManticoreQueryGrammar($connection), new ManticoreProcessor))->from('docs');
}

it('puts a filter value in its column type', function (string $column, mixed $value, mixed $binding) {
    expect(typedQuery()->where($column, $value)->getBindings())->toBe([$binding]);
})->with([
    'a number in a uint column' => ['views', '10', 10],
    'a qualified bigint column' => ['docs.id', '10', 10],
    'a number in a bool column' => ['on', '1', 1],
    'a number in a timestamp' => ['seen_at', '1000', 1000],
    'a number in a string column' => ['slug', 10, '10'],
    'a string in a string column' => ['slug', '10', '10'],
    'a uuid is never converted' => ['ref', '123', '123'],
    'a json path is not a column' => ['meta->views', '10', '10'],
    'a word stays for the server' => ['views', 'abc', 'abc'],
    'a decimal in an int column' => ['views', '1.5', '1.5'],
    'an oversized number' => ['id', '99999999999999999999', '99999999999999999999'],
    'a word in a timestamp column' => ['seen_at', 'not a date', 'not a date'],
]);

it('reads a date string as a Unix integer in a timestamp column', function () {
    expect(typedQuery()->where('seen_at', '2026-01-02 00:00:00')->getBindings())->toBe([strtotime('2026-01-02 00:00:00')]);
});

it('inlines a float, since PDO would quote it', function () {
    $q = typedQuery()->where('score', '>', 2.5)->where('views', '>', 1.5)->where('score', '<', '7.5');

    expect($q->toSql())->toBe(unlimited('select * from `docs` where `score` > 2.5 and `views` > 1.5 and `score` < 7.5'))
        ->and($q->getBindings())->toBe([]);
});

it('never inlines anything but a number', function () {
    $q = typedQuery()->where('score', '1 or 1=1');

    expect($q->toSql())->toContain('`score` = ?')
        ->and($q->getBindings())->toBe(['1 or 1=1']);
});

it('puts the values of whereIn and whereBetween in their column type', function () {
    expect(typedQuery()->whereIn('views', ['1', '2'])->getBindings())->toBe([1, 2])
        ->and(typedQuery()->whereNotIn('slug', [1])->getBindings())->toBe(['1'])
        ->and(typedQuery()->whereBetween('views', ['1', '2'])->getBindings())->toBe([1, 2]);
});

it('leaves a filter on a raw table without looking up any type', function () {
    $connection = mockManticoreConnection();
    $connection->shouldReceive('getTablePrefix')->andReturn('');
    $connection->shouldNotReceive('columnTypes');

    $query = new ManticoreQueryBuilder($connection, new ManticoreQueryGrammar($connection), new ManticoreProcessor);

    expect($query->fromRaw('docs')->where('views', '10')->getBindings())->toBe(['10']);
});

it('filters null by the empty value of the column', function (string $column, string $where) {
    expect(typedQuery()->whereNull($column)->toSql())->toBe(unlimited("select * from `docs` where {$where}"));
})->with([
    'an integer' => ['views', '`views` = 0'],
    'a bigint' => ['id', '`id` = 0'],
    'a float' => ['score', '`score` = 0'],
    'a bool' => ['on', '`on` = 0'],
    'a timestamp' => ['seen_at', '`seen_at` = 0'],
    'a string' => ['slug', "`slug` = ''"],
    'a qualified one' => ['docs.views', '`views` = 0'],
    'json keeps null' => ['meta', '`meta` is null'],
    'an unknown type' => ['other', '`other` is null'],
]);

it('filters not null by the empty value of the column', function (string $column, string $where) {
    expect(typedQuery()->whereNotNull($column)->toSql())->toBe(unlimited("select * from `docs` where {$where}"));
})->with([
    'an integer' => ['views', '`views` != 0'],
    'a string' => ['slug', "`slug` != ''"],
    'json keeps null' => ['meta', '`meta` is not null'],
    'an unknown type' => ['other', '`other` is not null'],
]);

it('keeps the boolean of a null filter', function () {
    expect(typedQuery()->where('slug', 'a')->orWhereNull('views')->toSql())
        ->toBe(unlimited('select * from `docs` where `slug` = ? or `views` = 0'));
});

it('compiles what a relation adds to a foreign key', function () {
    expect(typedQuery()->where('docs.views', 1)->whereNotNull('docs.views')->toSql())
        ->toBe(unlimited('select * from `docs` where `views` = ? and `views` != 0'));
});

it('gives an update and a delete without a filter a where that matches everything', function () {
    $connection = mockManticoreConnection();
    $connection->shouldReceive('getTablePrefix')->andReturn('');
    $connection->shouldReceive('update')->once()
        ->with('update `docs` set `views` = ? where `id` > ?', [1, 0])->andReturn(2);
    $connection->shouldReceive('delete')->once()
        ->with('delete from `docs` where `id` > ?', [0])->andReturn(2);

    $query = fn () => (new ManticoreQueryBuilder($connection, new ManticoreQueryGrammar($connection), new ManticoreProcessor))->from('docs');

    expect($query()->update(['views' => 1]))->toBe(2)
        ->and($query()->delete())->toBe(2);
});

it('leaves a delete by id without any extra filter', function () {
    $connection = mockManticoreConnection();
    $connection->shouldReceive('getTablePrefix')->andReturn('');
    $connection->shouldReceive('delete')->once()
        ->with('delete from `docs` where `id` = ?', [5])->andReturn(1);

    expect((new ManticoreQueryBuilder($connection, new ManticoreQueryGrammar($connection), new ManticoreProcessor))->from('docs')->delete(5))->toBe(1);
});

it('wraps each index of a table list', function () {
    expect(manticoreQuery('a,b')->limit(1)->toSql())->toBe('select * from `a`, `b` limit 1');
});

it('escapes the search syntax in matchText()', function () {
    expect(manticoreQuery()->matchText('a(b)|c-d!e@f~g"h&i/j^k$l=m<n\\o')->getBindings())
        ->toBe(['a\\(b\\)\\|c\\-d\\!e\\@f\\~g\\"h\\&i\\/j\\^k\\$l\\=m\\<n\\\\o']);
});

it('prefixes the field in matchText()', function () {
    expect(manticoreQuery()->matchText('a', 'title')->getBindings())->toBe(['@title a']);
});

it('puts match() first and merges the next one into it', function () {
    $q = manticoreQuery()->where('a', 1)->match('x')->where('b', 2)->match('y');

    expect($q->toSql())->toBe(unlimited('select * from `companies` where MATCH(?) and `a` = ? and `b` = ?'))
        ->and($q->getBindings())->toBe(['(x) (y)', 1, 2]);
});

it('leaves an or match() where it is, for the server to reject', function () {
    $q = manticoreQuery()->where('a', 1)->match('x', null, 'or');

    expect($q->toSql())->toBe(unlimited('select * from `companies` where `a` = ? or MATCH(?)'))
        ->and($q->getBindings())->toBe([1, 'x']);
});

it('compiles whereFullText() to MATCH', function () {
    expect(manticoreQuery()->whereFullText('title', 'a(b')->getBindings())->toBe(['@title a\\(b'])
        ->and(manticoreQuery()->whereFullText(['title', 'body'], 'x')->getBindings())->toBe(['@(title,body) x']);
});

it('writes a json path the way Manticore reads it', function () {
    expect(manticoreQuery('docs')->where('meta->a->b', 1)->toSql())->toBe(unlimited('select * from `docs` where meta.a.b = ?'))
        ->and(manticoreQuery('docs')->whereNull('meta->a')->toSql())->toBe(unlimited('select * from `docs` where meta.a is null'))
        ->and(manticoreQuery('docs')->where('meta->l[0]', 1)->toSql())->toBe(unlimited('select * from `docs` where meta.l[0] = ?'));
});

it('refuses a json path with anything but names and indexes, since it goes out unquoted', function (string $path) {
    expect(fn () => manticoreQuery('docs')->where($path, 1)->toSql())->toThrow(InvalidArgumentException::class, 'Invalid JSON path');
})->with(['meta->a` = 1 or `b', 'meta->a b', "meta->'a'", 'me ta->a', 'meta->a)--']);

it('compiles distinct() over named columns to a group by', function () {
    expect(manticoreQuery('docs')->select('a', 'b')->distinct()->toSql())
        ->toBe(unlimited('select `a`, `b` from `docs` group by `a`, `b`'))
        ->and(manticoreQuery('docs')->select('a')->groupBy('c')->distinct()->limit(1)->toSql())
        ->toBe('select `a` from `docs` group by `c`, `a` limit 1');
});

it('leaves a distinct() it cannot name for the server to reject', function () {
    expect(manticoreQuery('docs')->distinct()->toSql())->toBe(unlimited('select distinct * from `docs`'));
});

it('compiles upsert() to a REPLACE', function () {
    $connection = mockManticoreConnection();
    $connection->shouldReceive('getTablePrefix')->andReturn('');
    $connection->shouldReceive('insert')->once()
        ->with('replace into `docs` (`id`, `v`) values (?, ?), (?, ?)', [1, 2, 3, 4])->andReturn(true);

    $query = (new ManticoreQueryBuilder($connection, new ManticoreQueryGrammar($connection), new ManticoreProcessor))->from('docs');

    expect($query->upsert([['id' => 1, 'v' => 2], ['id' => 3, 'v' => 4]], 'id', ['v']))->toBe(2);
});

it('refuses increments before reaching the server', function (string $method) {
    expect(fn () => manticoreQuery('docs')->{$method}('views'))->toThrow(LogicException::class, $method);
})->with(['increment', 'decrement']);
