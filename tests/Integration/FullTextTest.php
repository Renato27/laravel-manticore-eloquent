<?php

use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function fullTextTable(): Builder
{
    return DB::connection('manticore')->table('fulltext_docs');
}

beforeEach(function () {
    try {
        DB::connection('manticore')->getPdo();
    } catch (Throwable $e) {
        $this->markTestSkipped('Manticore not reachable on the MySQL port: '.$e->getMessage());
    }

    Schema::connection('manticore')->dropIfExists('fulltext_docs');
    Schema::connection('manticore')->create('fulltext_docs', function (Blueprint $table) {
        $table->text('title');
        $table->text('body');
        $table->integer('views');
    });

    fullTextTable()->insert([
        ['id' => 1, 'title' => 'alpha', 'body' => 'beta', 'views' => 1],
        ['id' => 2, 'title' => 'alpha beta', 'body' => 'gamma', 'views' => 2],
        ['id' => 3, 'title' => 'delta', 'body' => 'alpha', 'views' => 3],
    ]);
});

afterEach(function () {
    try {
        Schema::connection('manticore')->dropIfExists('fulltext_docs');
    } catch (Throwable $e) {
        // Nothing to clean up when the server is not reachable.
    }
});

it('takes any user text in matchText() as plain words', function (string $text) {
    expect(fullTextTable()->matchText($text)->orderBy('id')->pluck('id')->all())->toBe([1, 2]);
})->with([
    'parenthesis' => ['alpha (beta'],
    'closing' => ['alpha) beta'],
    'pipe' => ['alpha | beta'],
    'minus' => ['alpha -beta'],
    'bang' => ['alpha !beta'],
    'at' => ['alpha @beta'],
    'tilde' => ['alpha ~beta'],
    'quote' => ['alpha "beta'],
    'single quote' => ["alpha 'beta"],
    'ampersand' => ['alpha & beta'],
    'slash' => ['alpha / beta'],
    'caret' => ['^alpha beta'],
    'dollar' => ['alpha beta$'],
    'equals' => ['=alpha beta'],
    'less than' => ['alpha << beta'],
    'backslash' => ['alpha \\ beta'],
]);

it('does not let matchText() turn a word into an operator', function () {
    expect(fullTextTable()->match('@title delta')->pluck('id')->all())->toBe([3])
        ->and(fullTextTable()->matchText('@title delta')->count())->toBe(0)
        ->and(fullTextTable()->matchText('gamma | delta')->count())->toBe(0)
        ->and(fullTextTable()->matchText('alpha -beta')->orderBy('id')->pluck('id')->all())->toBe([1, 2]);
});

it('scopes matchText() to a field', function () {
    expect(fullTextTable()->matchText('alpha', 'body')->pluck('id')->all())->toBe([3]);
});

it('keeps the search syntax in match()', function () {
    expect(fullTextTable()->match('alpha -beta')->pluck('id')->all())->toBe([3])
        ->and(fullTextTable()->match('gamma | delta')->orderBy('id')->pluck('id')->all())->toBe([2, 3]);
});

it('combines several match() calls, since Manticore takes one MATCH', function () {
    expect(fullTextTable()->match('alpha')->match('gamma')->pluck('id')->all())->toBe([2])
        ->and(fullTextTable()->where('views', '>', 0)->match('alpha')->where('views', '<', 3)->match('beta', 'title')->pluck('id')->all())->toBe([2])
        ->and(fullTextTable()->match('alpha')->matchText('(gamma')->match('gamma')->pluck('id')->all())->toBe([2]);
});

it('keeps other filters when match() comes after them', function () {
    expect(fullTextTable()->where('views', 2)->match('alpha')->pluck('id')->all())->toBe([2])
        ->and(fullTextTable()->whereIn('views', [1, 3])->match('alpha')->orderBy('id')->pluck('id')->all())->toBe([1, 3]);
});

it('fails loudly on an or next to match(), which the server does not have', function () {
    fullTextTable()->where('views', 1)->match('alpha', null, 'or')->get();
})->throws(QueryException::class);

it('runs whereFullText() on one or several fields', function () {
    expect(fullTextTable()->whereFullText('body', 'alpha')->pluck('id')->all())->toBe([3])
        ->and(fullTextTable()->whereFullText(['title', 'body'], 'gamma')->pluck('id')->all())->toBe([2])
        ->and(fullTextTable()->whereFullText('title', 'alpha (beta')->pluck('id')->all())->toBe([2]);
});
