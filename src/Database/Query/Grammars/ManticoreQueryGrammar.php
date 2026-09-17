<?php

namespace ManticoreEloquent\Database\Query\Grammars;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use InvalidArgumentException;
use ManticoreEloquent\Database\Query\ManticoreQueryBuilder;

class ManticoreQueryGrammar extends MySqlGrammar
{
    /**
     * Full-text MATCH, bound as a parameter so the search terms stay injection-safe.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $where
     * @return string
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
     * Manticore expects, for the same reason.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $where
     * @return string
     */
    protected function whereKnn(Builder $query, $where): string
    {
        if (is_array($where['vector'])) {
            $column = $this->wrap($where['column']);
            $target = '(' . implode(', ', array_map([$this, 'formatVectorValue'], $where['vector'])) . ')';
        } else {
            if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $where['column'])) {
                throw new InvalidArgumentException("Invalid KNN column [{$where['column']}].");
            }

            $column = $where['column'];
            $target = (string) (int) $where['vector'];
        }

        $sql = 'knn(' . $column . ', ' . (int) $where['k'] . ', ' . $target;

        $options = array_filter(
            $where['options'] ?? [],
            static fn ($value) => $value !== null
        );

        if ($options !== []) {
            $pairs = [];

            foreach ($options as $key => $value) {
                $pairs[] = $this->validateOptionKey($key) . '=' . $this->formatOptionValue($value);
            }

            $sql .= ', {' . implode(', ', $pairs) . '}';
        }

        return $sql . ')';
    }

    /**
     * Render a query-vector component as a bare float literal (e.g. 0.1, 5.0), using the
     * shortest round-trippable form and never scientific-notation surprises for normal ranges.
     *
     * @param  float  $value
     * @return string
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
     * @param  string|null  $field
     * @param  array<string, mixed>  $options
     * @return string
     */
    public function compileHighlight(?string $field = null, array $options = []): string
    {
        $args = [];

        if (! empty($options)) {
            $args[] = '{' . $this->formatHighlightOptions($options) . '}';
        } elseif ($field !== null) {
            $args[] = '{}';
        }

        if ($field !== null) {
            $args[] = "'" . str_replace("'", "\\'", $field) . "'";
        }

        return 'HIGHLIGHT(' . implode(', ', $args) . ')';
    }

    /**
     * Render HIGHLIGHT() options as comma-separated key=value pairs.
     *
     * @param  array<string, mixed>  $options
     * @return string
     */
    protected function formatHighlightOptions(array $options): string
    {
        $pairs = [];

        foreach ($options as $key => $value) {
            $pairs[] = $key . '=' . $this->formatHighlightOptionValue($value);
        }

        return implode(', ', $pairs);
    }

    /**
     * Render a single HIGHLIGHT() option value: bools as 0/1, numbers bare, strings quoted.
     *
     * @param  mixed  $value
     * @return string
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

        return "'" . str_replace("'", "\\'", (string) $value) . "'";
    }

    /**
     * Manticore has no row-level locking, so FOR UPDATE / LOCK IN SHARE MODE compile away.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  bool|string  $value
     * @return string
     */
    protected function compileLock(Builder $query, $value): string
    {
        return '';
    }

    /**
     * Compile the SELECT, then tack on Manticore's trailing OPTION and FACET clauses.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return string
     */
    public function compileSelect(Builder $query): string
    {
        $sql = parent::compileSelect($query);

        if (! $query instanceof ManticoreQueryBuilder || ! empty($query->unions)) {
            return $sql;
        }

        $sql .= $this->compileOptionClause($query);
        $sql .= $this->compileFacetClause($query);

        return $sql;
    }

    /**
     * REPLACE INTO is just an INSERT with the verb swapped.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $values
     * @return string
     */
    public function compileReplace(Builder $query, array $values): string
    {
        $insert = $this->compileInsert($query, $values);

        return preg_replace('/^insert into/i', 'replace into', $insert, 1);
    }

    /**
     * Build the trailing OPTION clause. Manticore caps results at max_matches (1000 by
     * default), so when someone pages past that we raise the cap unless they set it.
     *
     * @param  \ManticoreEloquent\Database\Query\ManticoreQueryBuilder  $query
     * @return string
     */
    protected function compileOptionClause(ManticoreQueryBuilder $query): string
    {
        $options = $query->options;
        unset($options['__facets']);

        if (! array_key_exists('max_matches', $options)) {
            $needed = (int) ($query->offset ?? 0) + (int) ($query->limit ?? 0);

            if ($needed > 1000) {
                $options['max_matches'] = $needed;
            }
        }

        if (empty($options)) {
            return '';
        }

        $pairs = [];

        foreach ($options as $key => $value) {
            $pairs[] = $this->validateOptionKey($key) . '=' . $this->formatOptionValue($value);
        }

        return ' OPTION ' . implode(', ', $pairs);
    }

    /**
     * Build the trailing FACET clause from whatever facets the query collected.
     *
     * @param  \ManticoreEloquent\Database\Query\ManticoreQueryBuilder  $query
     * @return string
     */
    protected function compileFacetClause(ManticoreQueryBuilder $query): string
    {
        $facets = $query->options['__facets'] ?? [];

        if (empty($facets)) {
            return '';
        }

        return ' ' . implode(' ', array_map(static fn ($f) => 'FACET ' . $f, $facets));
    }

    /**
     * An option name Manticore will accept is a bare identifier, so that — and not a list of
     * known options — is what gets enforced. Any option the server grows keeps working.
     *
     * @param  string  $key
     * @return string
     *
     * @throws \InvalidArgumentException
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
     * @param  mixed  $value
     * @return string
     *
     * @throws \InvalidArgumentException
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
                    $pairs[] = $this->validateOptionKey((string) $k) . '=' . $this->formatOptionValue($v);
                }

                return '(' . implode(',', $pairs) . ')';
            }

            return '(' . implode(',', array_map([$this, 'formatOptionValue'], $value)) . ')';
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
