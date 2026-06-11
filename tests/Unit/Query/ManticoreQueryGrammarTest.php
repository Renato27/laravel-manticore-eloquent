<?php

use Illuminate\Database\Connection;
use ManticoreEloquent\Database\Query\Grammars\ManticoreQueryGrammar;
use ManticoreEloquent\Database\Query\ManticoreQueryBuilder;
use ManticoreEloquent\Database\Query\Processors\ManticoreProcessor;

function manticoreQuery(string $table = 'companies'): ManticoreQueryBuilder
{
    $connection = Mockery::mock(Connection::class);
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

    expect($q->toSql())->toBe('select * from `companies` where `country` = ?');
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

    expect($sql)->toContain('knn(`embedding`, 10, (0.1, 0.2), 2000)');
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

    expect($q->toSql())->toBe('select * from `companies` where `id` in (?, ?, ?)');
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

it('does not append OPTION or FACET to a union query', function () {
    $q = manticoreQuery()->option('ranker', 'sph04')
        ->union(manticoreQuery()->where('country', 'BR'));

    expect($q->toSql())->not->toContain('OPTION');
});

it('treats an empty replace() as a no-op', function () {
    expect(manticoreQuery()->replace([]))->toBeTrue();
});
