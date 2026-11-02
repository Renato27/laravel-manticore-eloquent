<?php

namespace ManticoreEloquent\Database\Query\Grammars;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use ManticoreEloquent\Database\Query\DocumentId;
use ManticoreEloquent\Database\Query\ManticoreQueryBuilder;

class ManticoreQueryGrammar extends MySqlGrammar
{
    /**
     * The LIMIT and max_matches of a query without a limit: one below the largest int, which
     * knn() overflows on. The server only allocates for the rows it finds.
     */
    public const UNLIMITED = 2147483646;

    /**
     * Wrap a table, or each of the indexes in a list such as searchableAs() can give.
     *
     * @param  mixed  $table
     * @param  string|null  $prefix
     * @return string
     */
    public function wrapTable($table, $prefix = null)
    {
        if (is_string($table) && str_contains($table, ',')) {
            return implode(', ', array_map(fn ($index) => parent::wrapTable(trim($index), $prefix), explode(',', $table)));
        }

        return parent::wrapTable($table, $prefix);
    }

    /**
     * Full-text MATCH, bound as a parameter so the search terms stay injection-safe.
     *
     * @param  array  $where
     */
    protected function whereMatch(Builder $query, $where): string
    {
        return 'MATCH(?)';
    }

    /**
     * KNN vector search: knn(column, k, (v1, v2, …)|doc_id [, {ef=…, rescore=…}]). The vector
     * floats and the integer k are inlined as numeric literals — Manticore rejects quoted
     * (bound) values in the tuple, and the builder has already cast them, so this is
     * injection-safe. Option names are whitelisted and their values cast to the scalar type
     * Manticore expects, for the same reason. A UUID document id stays unquoted on purpose:
     * Manticore reads a quoted third argument as text to embed, which on an auto-embeddings
     * column returns unrelated documents without any error. A select never sends this form,
     * since it runs with the vector of that document. Anything else that does gets a syntax
     * error, and the id is validated, so it cannot carry anything but a UUID.
     *
     * @param  array  $where
     */
    protected function whereKnn(Builder $query, $where): string
    {
        if (is_array($where['vector'])) {
            $column = $this->wrap($where['column']);
            $target = '('.implode(', ', array_map([$this, 'formatVectorValue'], $where['vector'])).')';
        } else {
            if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $where['column'])) {
                throw new InvalidArgumentException("Invalid KNN column [{$where['column']}].");
            }

            $column = $where['column'];
            $target = is_string($where['vector'])
                ? (string) DocumentId::normalize($where['vector'])
                : (string) (int) $where['vector'];
        }

        $sql = 'knn('.$column.', '.(int) $where['k'].', '.$target;

        $options = array_filter(
            $where['options'] ?? [],
            static fn ($value) => $value !== null
        );

        if ($options !== []) {
            $pairs = [];

            foreach ($options as $key => $value) {
                $pairs[] = $this->validateOptionKey($key).'='.$this->formatOptionValue($value);
            }

            $sql .= ', {'.implode(', ', $pairs).'}';
        }

        return $sql.')';
    }

    /**
     * Render a query-vector component as a bare float literal (e.g. 0.1, 5.0), using the
     * shortest round-trippable form and never scientific-notation surprises for normal ranges.
     */
    protected function formatVectorValue(float $value): string
    {
        return var_export($value, true);
    }

    /**
     * Build a HIGHLIGHT() expression. Options render as {k='v', …}; the field, when given,
     * is a quoted literal. Manticore needs the options arg present before the field, so an
     * empty {} is emitted when a field is scoped without options.
     *
     * @param  array<string, mixed>  $options
     */
    public function compileHighlight(?string $field = null, array $options = []): string
    {
        $args = [];

        if (! empty($options)) {
            $args[] = '{'.$this->formatHighlightOptions($options).'}';
        } elseif ($field !== null) {
            $args[] = '{}';
        }

        if ($field !== null) {
            $args[] = "'".str_replace("'", "\\'", $field)."'";
        }

        return 'HIGHLIGHT('.implode(', ', $args).')';
    }

    /**
     * Render HIGHLIGHT() options as comma-separated key=value pairs.
     *
     * @param  array<string, mixed>  $options
     */
    protected function formatHighlightOptions(array $options): string
    {
        $pairs = [];

        foreach ($options as $key => $value) {
            $pairs[] = $key.'='.$this->formatHighlightOptionValue($value);
        }

        return implode(', ', $pairs);
    }

    /**
     * Render a single HIGHLIGHT() option value: bools as 0/1, numbers bare, strings quoted.
     */
    protected function formatHighlightOptionValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            return var_export($value, true);
        }

        return "'".str_replace("'", "\\'", (string) $value)."'";
    }

    /**
     * Manticore has no row-level locking, so FOR UPDATE / LOCK IN SHARE MODE compile away.
     *
     * @param  bool|string  $value
     */
    protected function compileLock(Builder $query, $value): string
    {
        return '';
    }

    /**
     * Compile the SELECT, then tack on Manticore's trailing OPTION and FACET clauses. Manticore
     * returns 20 rows by default, so a query without a limit asks for all of them, as Eloquent
     * promises.
     */
    public function compileSelect(Builder $query): string
    {
        if (! $query instanceof ManticoreQueryBuilder) {
            return parent::compileSelect($query);
        }

        if ($query->aggregate === null) {
            $query = $this->withManticoreDefaults($query);
        }

        $sql = parent::compileSelect($query);

        $sql .= $this->compileOptionClause($query);
        $sql .= $this->compileFacetClause($query);

        return $sql;
    }

    /**
     * What a row-returning select needs on Manticore: a limit that reaches every row, columns it
     * can read, and a GROUP BY in place of the DISTINCT it does not have.
     */
    protected function withManticoreDefaults(ManticoreQueryBuilder $query): ManticoreQueryBuilder
    {
        $query = clone $query;

        if ($query->limit === null) {
            $query->limit(self::UNLIMITED);
        }

        if (is_string($query->from)) {
            $own = last(preg_split('/\s+as\s+/i', $query->from)).'.';

            $query->columns = $query->columns === null ? null : array_map(fn ($column) => $this->selectableColumn($column, $own), $query->columns);
            $query->groups = $query->groups === null ? null : array_map(fn ($column) => $this->selectableColumn($column, $own), $query->groups);
            $query->orders = $query->orders === null ? null : array_map(function ($order) use ($own) {
                if (isset($order['column'])) {
                    $order['column'] = $this->selectableColumn($order['column'], $own);
                }

                return $order;
            }, $query->orders);
        }

        if ($query->distinct === true && ! in_array($query->columns, [null, ['*']], true)) {
            $query->distinct = false;
            $query->groups = array_merge($query->groups ?? [], $query->columns);
        }

        return $query;
    }

    /**
     * A column as Manticore can read it. It takes a text field of the query's own table only
     * without the table's name, has no table.* other than a plain *, and returns no rows at all for
     * an alias that repeats the column in a join.
     */
    protected function selectableColumn(mixed $column, string $own): mixed
    {
        if (! is_string($column)) {
            return $column;
        }

        $column = preg_replace('/^(\w+\.(\w+))\s+as\s+\2$/i', '$1', $column);

        if ($column === $own.'*') {
            return '*';
        }

        return str_starts_with($column, $own) ? substr($column, strlen($own)) : $column;
    }

    /**
     * Manticore rejects `table`.`column`, so a qualified name goes out bare, and can therefore only
     * hold plain names.
     *
     * @param  array  $segments
     * @return string
     *
     * @throws InvalidArgumentException
     */
    protected function wrapSegments($segments)
    {
        if (count($segments) === 1) {
            return parent::wrapSegments($segments);
        }

        foreach ($segments as $segment) {
            if (! preg_match('/^(\w+|\*)$/', $segment)) {
                throw new InvalidArgumentException('Invalid column ['.implode('.', $segments).'].');
            }
        }

        $segments[0] = trim($this->wrapTable($segments[0]), '`');

        return implode('.', $segments);
    }

    /**
     * Manticore only has INNER and LEFT JOIN. It reads any other word there as an alias and runs an
     * inner join, so a right join would give the wrong rows without an error.
     *
     * @see https://manual.manticoresearch.com/Searching/Joining
     *
     * @param  array  $joins
     * @return string
     *
     * @throws LogicException
     */
    protected function compileJoins(Builder $query, $joins)
    {
        foreach ($joins as $join) {
            if (! in_array($join->type, ['inner', 'left'], true)) {
                throw new LogicException("Manticore only has inner and left joins, so it cannot run a {$join->type} join.");
            }
        }

        return parent::compileJoins($query, $joins);
    }

    /**
     * Manticore's sub-select takes no alias, which fromSub() always gives it.
     *
     * @param  mixed  $table
     * @return string
     */
    protected function compileFrom(Builder $query, $table)
    {
        $from = parent::compileFrom($query, $table);

        return $this->isSubSelect($query) ? preg_replace('/\)\s+as\s+`?\w+`?$/i', ')', $from) : $from;
    }

    /**
     * Whether a query selects from a sub-select.
     */
    protected function isSubSelect(Builder $query): bool
    {
        return $this->isExpression($query->from) && str_starts_with(strtolower((string) $this->getValue($query->from)), '(select');
    }

    /**
     * Manticore's COUNT takes a star or DISTINCT, so count('column') counts the rows, which is the
     * same thing where there is no NULL.
     *
     * @see https://manual.manticoresearch.com/Searching/Grouping
     *
     * @param  array  $aggregate
     * @return string
     */
    protected function compileAggregate(Builder $query, $aggregate)
    {
        if ($aggregate['function'] === 'count' && ! $query->distinct) {
            $aggregate['columns'] = ['*'];
        }

        return parent::compileAggregate($query, $aggregate);
    }

    /**
     * REPLACE INTO is just an INSERT with the verb swapped.
     */
    public function compileReplace(Builder $query, array $values): string
    {
        $insert = $this->compileInsert($query, $values);

        return preg_replace('/^insert into/i', 'replace into', $insert, 1);
    }

    /**
     * Partial REPLACE: REPLACE INTO ... SET ... WHERE id = ?. Expression values (e.g. a
     * vector tuple) are inlined; every other value is a binding, and the id binding goes last.
     */
    public function compileReplaceSet(Builder $query, array $values): string
    {
        $assignments = [];

        foreach ($values as $column => $value) {
            $assignments[] = $this->wrap($column).' = '.$this->parameter($value);
        }

        return 'replace into '.$this->wrapTable($query->from)
            .' set '.implode(', ', $assignments)
            .' where '.$this->wrap('id').' = ?';
    }

    /**
     * Eloquent qualifies keys and foreign keys with the table, and Manticore rejects
     * `table`.`column`, so filters on the query's own table go out bare. Queries with a join are
     * left as they are.
     *
     * @param  Builder  $query
     * @return array
     */
    protected function compileWheresToArray($query)
    {
        if (! empty($query->joins) || ! is_string($query->from)) {
            return parent::compileWheresToArray($query);
        }

        $qualifier = last(preg_split('/\s+as\s+/i', $query->from)).'.';
        $wheres = [];

        foreach ($query->wheres as $where) {
            if (is_string($where['column'] ?? null) && str_starts_with($where['column'], $qualifier)) {
                $where['column'] = substr($where['column'], strlen($qualifier));
            }

            $wheres[] = $where;
        }

        $bare = clone $query;
        $bare->wheres = $wheres;

        return parent::compileWheresToArray($bare);
    }

    /**
     * A JSON path is written meta.a.b on Manticore. It cannot be quoted, so it can only hold names
     * and array indexes.
     *
     * @param  string  $value
     * @return string
     *
     * @throws InvalidArgumentException
     */
    protected function wrapJsonSelector($value)
    {
        if (! preg_match('/^\w+(->\w+(\[\d+\])*)+$/', $value)) {
            throw new InvalidArgumentException("Invalid JSON path [{$value}].");
        }

        return str_replace('->', '.', $value);
    }

    /**
     * Manticore writes a value at a JSON path directly, without a function.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return string
     */
    protected function compileJsonUpdateColumn($key, $value)
    {
        return $this->wrap($key).' = '.(is_bool($value) ? (int) $value : $this->parameter($value));
    }

    /**
     * Manticore has no NULL outside JSON: a column without a value holds its type's empty value.
     *
     * @see https://manual.manticoresearch.com/Searching/Expressions
     *
     * @param  array  $where
     * @return string
     */
    protected function whereNull(Builder $query, $where)
    {
        $empty = $this->emptyLiteral($query, $where['column']);

        return $this->wrap($where['column']).($empty === null ? ' is null' : ' = '.$empty);
    }

    /**
     * The opposite of whereNull().
     *
     * @param  array  $where
     * @return string
     */
    protected function whereNotNull(Builder $query, $where)
    {
        $empty = $this->emptyLiteral($query, $where['column']);

        return $this->wrap($where['column']).($empty === null ? ' is not null' : ' != '.$empty);
    }

    /**
     * What a column without a value holds, for the types that can be filtered on it.
     */
    protected function emptyLiteral(Builder $query, mixed $column): ?string
    {
        if (! $query instanceof ManticoreQueryBuilder) {
            return null;
        }

        return match ($query->columnType($column)) {
            'uint', 'bigint', 'float', 'bool', 'timestamp' => '0',
            'string' => "''",
            default => null,
        };
    }

    /**
     * Eloquent qualifies updated_at with the table, and Manticore rejects `table`.`column`.
     *
     * @return string
     */
    protected function compileUpdateColumns(Builder $query, array $values)
    {
        $columns = [];

        foreach ($values as $key => $value) {
            $columns[$this->isJsonSelector($key) ? $key : Str::afterLast($key, '.')] = $value;
        }

        return parent::compileUpdateColumns($query, $columns);
    }

    /**
     * Build the trailing OPTION clause. Manticore caps results at max_matches (1000 by
     * default), so a query that reaches past that raises the cap unless it was set by hand. The
     * outer select of a sub-select takes no OPTION.
     */
    protected function compileOptionClause(ManticoreQueryBuilder $query): string
    {
        $options = $query->options;
        unset($options['__facets']);

        if (! array_key_exists('max_matches', $options) && ! $this->isSubSelect($query)) {
            $needed = (int) ($query->offset ?? 0) + (int) ($query->limit ?? 0);

            if ($needed > 1000) {
                $options['max_matches'] = min($needed, self::UNLIMITED);
            }
        }

        if (empty($options)) {
            return '';
        }

        $pairs = [];

        foreach ($options as $key => $value) {
            $pairs[] = $this->validateOptionKey($key).'='.$this->formatOptionValue($value);
        }

        return ' OPTION '.implode(', ', $pairs);
    }

    /**
     * Build the trailing FACET clause from whatever facets the query collected.
     */
    protected function compileFacetClause(ManticoreQueryBuilder $query): string
    {
        $facets = $query->options['__facets'] ?? [];

        if (empty($facets)) {
            return '';
        }

        return ' '.implode(' ', array_map(static fn ($f) => 'FACET '.$f, $facets));
    }

    /**
     * An option name Manticore will accept is a bare identifier, so that — and not a list of
     * known options — is what gets enforced. Any option the server grows keeps working.
     *
     *
     * @throws InvalidArgumentException
     */
    protected function validateOptionKey(string $key): string
    {
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
            throw new InvalidArgumentException("Invalid Manticore option name [{$key}].");
        }

        return $key;
    }

    /**
     * Render an OPTION value: bools as 0/1, ints as-is, floats with their decimal point,
     * arrays as (a,b) or (k=v), and strings verbatim — most Manticore options are bare
     * keywords, so a string is left unquoted but must look like one.
     *
     *
     * @throws InvalidArgumentException
     */
    protected function formatOptionValue(mixed $value): string
    {
        if ($value instanceof Expression) {
            return (string) $value->getValue($this);
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            return var_export($value, true);
        }

        if (is_array($value)) {
            $isAssoc = array_keys($value) !== range(0, count($value) - 1);

            if ($isAssoc) {
                $pairs = [];
                foreach ($value as $k => $v) {
                    $pairs[] = $this->validateOptionKey((string) $k).'='.$this->formatOptionValue($v);
                }

                return '('.implode(',', $pairs).')';
            }

            return '('.implode(',', array_map([$this, 'formatOptionValue'], $value)).')';
        }

        $value = (string) $value;

        if (preg_match('/^[A-Za-z0-9_.\-]+$/', $value) || preg_match("/^'[^'\\\\]*'$/", $value)) {
            return $value;
        }

        throw new InvalidArgumentException(
            "Invalid Manticore option value [{$value}]. Wrap it in DB::raw() to pass it through."
        );
    }
}
