<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use ManticoreEloquent\Concerns\HasManticore;
use ManticoreEloquent\Database\Schema\ManticoreSchemaGrammar;
use ManticoreEloquent\Eloquent\ManticoreModel;
use ManticoreEloquent\ManticoreEloquentServiceProvider;

/**
 * Compile a Blueprint to SQL through the (booted) Manticore grammar, so the table-option
 * macros registered by the service provider are available. Mirrors the cross-Laravel
 * constructor handling used by the Unit schema tests, but with a live connection object
 * (no server needed — toSql() never touches the PDO).
 *
 * @return array<int, string>
 */
function manticoreBootedSchemaSql(string $table, callable $define): array
{
    $connection = DB::connection('manticore');
    $connection->useDefaultSchemaGrammar(); // Laravel 12 reads the grammar off the connection
    $grammar = $connection->getSchemaGrammar() ?? new ManticoreSchemaGrammar($connection);

    $ctor = (new ReflectionClass(Blueprint::class))->getConstructor();
    $connectionFirst = $ctor && $ctor->getParameters()[0]->getName() === 'connection';

    if ($connectionFirst) {
        $blueprint = new Blueprint($connection, $table);
        $define($blueprint);

        return $blueprint->toSql();
    }

    $blueprint = new Blueprint($table);
    $define($blueprint);

    return $blueprint->toSql($connection, $grammar);
}

/**
 * Boots a Testbench app (no live server needed) to exercise the two ways a model reaches
 * Manticore: the HasManticore gateway and the ManticoreModel base class.
 */
class GatewayCompany extends Model
{
    use HasManticore;

    protected $table = 'companies';

    public function searchableAs(): string
    {
        return 'companies_rt';
    }
}

class GatewayPlainModel extends Model
{
    use HasManticore;

    protected $table = 'plain_models';
}

class DefaultArticle extends ManticoreModel
{
    protected $table = 'articles_rt';
}

it('routes Model::manticore() through the manticore connection', function () {
    $query = GatewayCompany::manticore();

    expect($query->getConnection()->getName())->toBe('manticore');
});

it('picks the index name from searchableAs()', function () {
    expect(GatewayCompany::manticore()->getModel()->getTable())->toBe('companies_rt');
});

it('falls back to the model table when searchableAs() is absent', function () {
    expect(GatewayPlainModel::manticore()->getModel()->getTable())->toBe('plain_models');
});

it('exposes the full-text helpers on the gateway builder', function () {
    $sql = GatewayCompany::manticore()->match('fintech')->where('country', 'BR')->toSql();

    expect($sql)->toContain('MATCH(?)');
});

it('defaults a ManticoreModel to the manticore connection', function () {
    expect((new DefaultArticle)->getConnectionName())->toBe('manticore');
    expect(DefaultArticle::query()->getConnection()->getName())->toBe('manticore');
});

it('honors an explicit connection set on the model', function () {
    $model = new DefaultArticle;
    $model->setConnection('something-else');

    expect($model->getConnectionName())->toBe('something-else');
});

it('registers the Blueprint macros for Manticore column types', function () {
    expect(Blueprint::hasMacro('mva'))->toBeTrue();
    expect(Blueprint::hasMacro('mva64'))->toBeTrue();
    expect(Blueprint::hasMacro('floatVector'))->toBeTrue();
    expect(Blueprint::hasMacro('manticoreOptions'))->toBeTrue();
    expect(Blueprint::hasMacro('minInfixLen'))->toBeTrue();
    expect(Blueprint::hasMacro('morphology'))->toBeTrue();
});

it('appends table-level options from the minInfixLen/morphology macros', function () {
    $sql = manticoreBootedSchemaSql('docs_rt', function (Blueprint $blueprint) {
        $blueprint->create();
        $blueprint->text('title');
        $blueprint->minInfixLen(2);
        $blueprint->morphology('stem_en');
    })[0];

    expect($sql)->toContain("min_infix_len='2'");
    expect($sql)->toContain("morphology='stem_en'");
});

it('merges options passed through manticoreOptions()', function () {
    $sql = manticoreBootedSchemaSql('docs_rt', function (Blueprint $blueprint) {
        $blueprint->create();
        $blueprint->text('title');
        $blueprint->manticoreOptions(['min_infix_len' => 3, 'morphology' => 'lemmatize_en_all']);
    })[0];

    expect($sql)->toContain("min_infix_len='3'");
    expect($sql)->toContain("morphology='lemmatize_en_all'");
});

it('does not overwrite a manually-defined manticore connection', function () {
    config(['database.connections.manticore' => ['driver' => 'manticore', 'host' => 'custom-host']]);

    (new ManticoreEloquentServiceProvider($this->app))->boot();

    expect(config('database.connections.manticore.host'))->toBe('custom-host');
});

it('leaves the key unqualified on the gateway, since Manticore rejects qualified columns', function () {
    expect(GatewayCompany::manticore()->whereKey(7)->toBase()->toSql())
        ->toBe(unlimited('select * from `companies_rt` where `id` = ?'));
});

it('keeps the key qualified on the normal connection of a gateway model', function () {
    expect((new GatewayCompany)->getQualifiedKeyName())->toBe('companies.id');
});

it('turns dates into Unix integers on a ManticoreModel', function () {
    expect((new DefaultArticle)->fromDateTime('2026-01-02 03:04:05'))
        ->toBe(Carbon::parse('2026-01-02 03:04:05')->getTimestamp())
        ->and((new DefaultArticle)->fromDateTime(null))->toBeNull();
});

it('turns dates into Unix integers on a gateway model only while it is on Manticore', function () {
    $date = '2026-01-02 03:04:05';

    expect(GatewayCompany::manticore()->getModel()->fromDateTime($date))
        ->toBe(Carbon::parse($date)->getTimestamp())
        ->and(GatewayCompany::manticore()->getModel()->fromDateTime(null))->toBeNull()
        ->and((new GatewayCompany)->fromDateTime($date))->toBe($date);
});

it('binds a date as a Unix integer on the Manticore connection', function () {
    $date = Carbon::parse('2026-01-02 03:04:05');

    expect(DB::connection('manticore')->prepareBindings([$date, true, 'text', 5]))
        ->toBe([$date->getTimestamp(), 1, 'text', 5]);
});

it('compiles relation queries without the table qualifier', function () {
    $post = new class extends ManticoreModel
    {
        protected $table = 'posts';

        public function notes()
        {
            return $this->hasMany(DefaultArticle::class, 'post_id');
        }
    };
    $post->setAttribute('id', 1);

    expect($post->notes()->toBase()->toSql())->toContain(' where `post_id` = ? and `post_id` ');
});

it('leaves qualifying the key to the framework', function () {
    expect((new DefaultArticle)->getQualifiedKeyName())->toEndWith('.id');
});

it('opens the gateway on a ManticoreModel too', function () {
    expect(DefaultArticle::manticore()->where('a', 1)->toBase()->toSql())
        ->toEndWith(unlimited('where `a` = ?'));
});

it('writes Unix integers on any Manticore connection, whatever its name', function () {
    config(['database.connections.manticore_two' => config('database.connections.manticore')]);

    $model = (new DefaultArticle)->setConnection('manticore_two');

    expect($model->fromDateTime('2026-01-02 03:04:05'))
        ->toBe(Carbon::parse('2026-01-02 03:04:05')->getTimestamp());
});

it('keeps the date format of a ManticoreModel moved to another kind of connection', function () {
    config(['database.connections.other' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);

    $model = (new DefaultArticle)->setConnection('other');

    expect($model->fromDateTime('2026-01-02 03:04:05'))->toBe('2026-01-02 03:04:05');
});
