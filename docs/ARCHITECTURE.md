# Architecture & decisions

## Core idea

The requirement is "use Eloquent as usual, but pointed at Manticore instead of the
database". That is, by definition, a **Laravel database driver**. Since Manticore speaks
the **MySQL protocol** (port 9306) and the whole Eloquent/Query Builder stack is built on a
`Connection` + `Grammar` abstraction, the strategy is:

> implement a thin `Connection` + `Grammar` for Manticore and **inherit all of Eloquent**,
> instead of reimplementing it method by method.

The payoff: `where`, `find`, `create`, `update`, `delete`, `paginate`, `chunk`, `cursor`,
scopes, casts, model events, relations and migrations all come for free. We only write code
where Manticore's SQL differs from MySQL.

## Components

```
src/
├── ManticoreEloquentServiceProvider.php   — registers the driver, connection and schema macros
├── Concerns/HasManticore.php              — trait: Model::manticore() gateway
├── Eloquent/ManticoreModel.php            — base model whose default connection is Manticore
└── Database/
    ├── ManticoreConnector.php             — PDO (MySQL) without the incompatible SETs; emulated prepares
    ├── ManticoreConnection.php            — MySqlConnection + its own grammar/schema/processor/query
    ├── Query/
    │   ├── ManticoreQueryBuilder.php      — match/knn/highlight/option/maxMatches/facet/replace
    │   ├── Grammars/ManticoreQueryGrammar.php  — MATCH(?), knn(), HIGHLIGHT(), OPTION, FACET, REPLACE, no lock
    │   └── Processors/ManticoreProcessor.php
    └── Schema/
        ├── ManticoreSchemaGrammar.php     — CREATE/ALTER/DROP TABLE with Manticore types
        └── ManticoreSchemaBuilder.php     — hasTable/getColumnListing via SHOW TABLES/DESC
```

## How the gateway works

`Model::manticore()` creates the instance, switches its connection to the registered one
(`manticore`), sets the table from `searchableAs()` (or `$table`) and returns `newQuery()` —
a standard Eloquent Builder. Because `Connection::query()` returns the
`ManticoreQueryBuilder`, the full-text helpers (`match`, `option`, etc.) are available in the
chain (the Eloquent Builder forwards unknown methods to the base query builder).

`ManticoreModel` is the "100% Manticore" alternative: the model's default connection is
already Manticore, so `Model::where(...)`, `create(...)`, etc. go straight there, without
`manticore()`.

## SQL differences handled

| Feature | MySQL | Manticore (this driver) |
|---|---|---|
| Full-text | `MATCH ... AGAINST` | `MATCH('expr')` as a single predicate, bound parameter |
| Vector search | — | `knn(col, k, (vector) [, ef])` predicate via `knn()` (floats inlined) |
| Highlighting | — | `HIGHLIGHT([opts][, field])` select column via `highlight()` |
| Tuning | — | `OPTION k=v, …` clause (with automatic `max_matches` on deep paging) |
| Facets | — | `FACET field` clause |
| Full-doc write | optional `REPLACE` | `REPLACE INTO` exposed via `replace()` |
| Locks | `FOR UPDATE` | none (no-op) |
| Schema | rich `CREATE TABLE` | simple `CREATE TABLE`; implicit `id`; its own types; `text indexed/stored`; table options (`min_infix_len`, `morphology`) |
| Introspection | information_schema | `SHOW TABLES` / `DESC` |
| Prepares | native | emulated (bindings interpolated by PDO) |

## What comes "for free" from Eloquent

Reads (`find`, `firstOrFail`, `value`, `exists`, `latest/oldest`, `count/sum/avg/min/max`
aggregates, `cursor/lazy/chunk`, local/global scopes), two-way casts, mutators/accessors,
`$appends`, timestamps, events/observers, mass assignment (`fillable`/`guarded`), pagination
and eager loading.

## Known limitations

- No ACID transactions / locks.
- `UPDATE` only changes attributes; use `replace()` to rewrite a full document.
- JOINs across indexes are limited in Manticore — prefer denormalized indexes.
- `ManticoreSchemaBuilder` covers the common cases (create/add/drop/hasTable/columns);
  advanced operations (column rename, secondary indexes) may need `DB::statement`.

## Not yet implemented

These are deliberately out of scope for now — not blocked by the design, just unbuilt:

1. **Suggest / autocomplete** (`CALL SUGGEST`) and **percolate queries** as helpers. These
   aren't `SELECT`s, so they don't ride the Builder → Grammar → SELECT path the rest of the
   library inherits for free; they'd need their own statement-running helpers.
2. **Optional sync** database → Manticore (Scout `searchable()` style), for those who keep
   the relational store as the source of truth. This is effectively a second integration
   surface (a Scout engine), better suited to its own package.
3. **`ALTER … RENAME`** of a column. This one *is* an engine limitation: Manticore's `ALTER`
   only adds/drops columns, so a rename has no compilable form — use `DB::statement` (see
   *Known limitations*).

Already shipped from earlier iterations of this list: KNN vector search (`knn()`),
`HIGHLIGHT()` (`highlight()`), and the advanced `text indexed/stored` + table-option
(`min_infix_len`, `morphology`) schema support.

## Verification

Tests are split into three suites:

- `tests/Unit` — validate SQL/DDL compilation **offline** (no server), plus the DSN builder
  and the `dropAllTables` loop (mocked connection).
- `tests/Feature` — boot a Testbench app (no live server) to check the `HasManticore`
  gateway, the `ManticoreModel` connection defaults and the Blueprint macro registration.
- `tests/Integration` — require a real Manticore on the configured MySQL port (defaults to
  9306; override with `MANTICORE_HOST`/`MANTICORE_PORT`) and cover the full cycle
  (create index → insert → search → update/replace → delete), `replace()`, deep pagination,
  KNN/`HIGHLIGHT()`, table options and schema introspection (`hasTable`/`getColumnListing`).

### Coverage

Coverage needs a driver (PCOV recommended, or Xdebug). With the integration suite running
against a real server, line coverage is **~99%**:

```
composer test:coverage          # full report
composer test:coverage-min      # fails under 95%
```

The only uncovered line is the `withTablePrefix()` branch in `ManticoreConnection`, which
only exists on Laravel 10/11 and is unreachable on Laravel 12.
