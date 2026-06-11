<?php

namespace ManticoreEloquent\Database\Query;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Arr;

class ManticoreQueryBuilder extends Builder
{
    /**
     * Manticore query OPTION pairs, e.g. ['ranker' => 'sph04', 'max_matches' => 5000].
     *
     * @var array<string, mixed>
     */
    public array $options = [];

    /**
     * Add a full-text MATCH() condition. Pass a $field to scope it (e.g. 'title'),
     * or leave it null to search every field.
     *
     * @param  string  $query
     * @param  string|null  $field
     * @param  string  $boolean
     * @return static
     */
    public function match(string $query, ?string $field = null, string $boolean = 'and'): static
    {
        $expression = $field !== null && $field !== '' && $field !== '*'
            ? '@' . $field . ' ' . $query
            : $query;

        $this->wheres[] = [
            'type'    => 'Match',
            'value'   => $expression,
            'boolean' => $boolean,
        ];

        $this->addBinding($expression, 'where');

        return $this;
    }

    /**
     * Set a single Manticore query OPTION.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return static
     */
    public function option(string $key, mixed $value): static
    {
        $this->options[$key] = $value;

        return $this;
    }

    /**
     * Shortcut for the max_matches OPTION.
     *
     * @param  int  $value
     * @return static
     */
    public function maxMatches(int $value): static
    {
        return $this->option('max_matches', $value);
    }

    /**
     * Add a KNN (vector) search predicate: knn(column, k, (vector) [, ef]).
     * Manticore needs the query vector as bare float literals inside the tuple — PDO would
     * quote bound parameters as strings, which the parser rejects — so the values are cast
     * to float and inlined (injection-safe by construction).
     * Pair it with selectRaw('knn_dist() as distance') to read the similarity score.
     *
     * @param  string  $column
     * @param  array<int, int|float>  $vector
     * @param  int  $k
     * @param  int|null  $ef
     * @param  string  $boolean
     * @return static
     */
    public function knn(string $column, array $vector, int $k = 10, ?int $ef = null, string $boolean = 'and'): static
    {
        $this->wheres[] = [
            'type'    => 'Knn',
            'column'  => $column,
            'vector'  => array_map(static fn ($value) => (float) $value, array_values($vector)),
            'k'       => $k,
            'ef'      => $ef,
            'boolean' => $boolean,
        ];

        return $this;
    }

    /**
     * Add a HIGHLIGHT() column to the select. With no field it highlights every field
     * using the query from the MATCH() in the same statement; pass a field to scope it.
     * $options maps to Manticore's highlight settings, e.g. ['before_match' => '<b>'].
     *
     * @param  string|null  $field
     * @param  array<string, mixed>  $options
     * @param  string  $alias
     * @return static
     */
    public function highlight(?string $field = null, array $options = [], string $alias = 'highlight'): static
    {
        if (is_null($this->columns)) {
            $this->columns = ['*'];
        }

        $this->columns[] = new Expression(
            $this->grammar->compileHighlight($field, $options) . ' as ' . $this->grammar->wrap($alias)
        );

        return $this;
    }

    /**
     * Add a FACET clause. Facets come back as an extra result set, so reading them
     * usually means going through the raw statement.
     *
     * @param  string  $field
     * @param  string|null  $alias
     * @return static
     */
    public function facet(string $field, ?string $alias = null): static
    {
        $this->options['__facets'][] = $alias ? "{$field} AS {$alias}" : $field;

        return $this;
    }

    /**
     * REPLACE the whole document. Manticore's UPDATE only touches numeric/attribute
     * columns, so anything involving full-text fields has to go through REPLACE.
     * Takes a single row or a list of rows, just like insert().
     *
     * @param  array<string, mixed>|array<int, array<string, mixed>>  $values
     * @return bool
     */
    public function replace(array $values): bool
    {
        if (empty($values)) {
            return true;
        }

        if (! is_array(reset($values))) {
            $values = [$values];
        } else {
            foreach ($values as $key => $value) {
                ksort($value);
                $values[$key] = $value;
            }
        }

        $this->applyBeforeQueryCallbacks();

        return $this->connection->insert(
            $this->grammar->compileReplace($this, $values),
            $this->cleanBindings(Arr::flatten($values, 1))
        );
    }
}
