<?php

namespace ManticoreEloquent\Database\Query;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;
use RuntimeException;

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
     * Add a basic where clause, with the value in its column's type.
     *
     * @param  mixed  $column
     * @param  mixed  $operator
     * @param  mixed  $value
     * @param  string  $boolean
     * @return static
     */
    public function where($column, $operator = null, $value = null, $boolean = 'and')
    {
        if (is_string($column)) {
            [$value, $operator] = $this->prepareValueAndOperator($value, $operator, func_num_args() === 2);

            $value = $this->castToColumn($column, $value);
        }

        return parent::where($column, $operator, $value, $boolean);
    }

    /**
     * Add a "where in" clause, with the values in their column's type.
     *
     * @param  string  $column
     * @param  mixed  $values
     * @param  string  $boolean
     * @param  bool  $not
     * @return static
     */
    public function whereIn($column, $values, $boolean = 'and', $not = false)
    {
        if (is_array($values)) {
            $values = array_map(fn ($value) => $this->castToColumn($column, $value), $values);
        }

        return parent::whereIn($column, $values, $boolean, $not);
    }

    /**
     * Add a "where between" clause, with the values in their column's type.
     *
     * @param  string  $column
     * @param  iterable  $values
     * @param  string  $boolean
     * @param  bool  $not
     * @return static
     */
    public function whereBetween($column, iterable $values, $boolean = 'and', $not = false)
    {
        if (is_array($values)) {
            $values = array_map(fn ($value) => $this->castToColumn($column, $value), Arr::flatten($values));
        }

        return parent::whereBetween($column, $values, $boolean, $not);
    }

    /**
     * Insert rows, with the values in their columns' types.
     *
     * @param  array  $values
     * @return bool
     */
    public function insert(array $values)
    {
        return parent::insert($this->castRows($values));
    }

    /**
     * Insert a row and get its id, with the values in their columns' types.
     *
     * @param  array  $values
     * @param  string|null  $sequence
     * @return int
     */
    public function insertGetId(array $values, $sequence = null)
    {
        return parent::insertGetId($this->castRow($values), $sequence);
    }

    /**
     * Update documents, with the values in their columns' types.
     *
     * @param  array  $values
     * @return int
     */
    public function update(array $values)
    {
        return parent::update($this->castRow($values));
    }

    /**
     * Put a value in the type of its column. Manticore does not convert: a filter rejects a
     * quoted number, UPDATE skips it without an error, a string column drops an unquoted one,
     * and a timestamp column misreads a quoted value. PDO quotes anything but an int, so a
     * float is inlined, which is safe since it has been cast to one.
     *
     * @param  mixed  $column
     * @param  mixed  $value
     * @return mixed
     */
    protected function castToColumn(mixed $column, mixed $value): mixed
    {
        if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
            return $value;
        }

        $type = $this->columnType($column);

        return match (true) {
            $type === 'string' => (string) $value,
            is_float($value), $type === 'float' && is_numeric($value) => new Expression(var_export((float) $value, true)),
            $type === 'timestamp' && is_string($value) => $this->timestampFrom($value),
            in_array($type, ['uint', 'bigint', 'bool'], true) && filter_var($value, FILTER_VALIDATE_INT) !== false => (int) $value,
            default => $value,
        };
    }

    /**
     * A Unix integer from a date string or a number in a string. Anything else is left for
     * the server to reject.
     *
     * @param  string  $value
     * @return int|string
     */
    protected function timestampFrom(string $value): int|string
    {
        if (filter_var($value, FILTER_VALIDATE_INT) !== false) {
            return (int) $value;
        }

        return strtotime($value) ?: $value;
    }

    /**
     * The type of a column of this query's table, if it can be known.
     *
     * @param  mixed  $column
     * @return string|null
     */
    protected function columnType(mixed $column): ?string
    {
        if (! is_string($column) || ! is_string($this->from)) {
            return null;
        }

        $names = preg_split('/\s+as\s+/i', $this->from);

        return $this->connection->columnTypes($names[0])[Str::after($column, last($names) . '.')] ?? null;
    }

    /**
     * Put one row in its columns' types.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function castRow(array $row): array
    {
        foreach ($row as $column => $value) {
            $row[$column] = $this->castToColumn($column, $value);
        }

        return $row;
    }

    /**
     * Put a row, or a list of rows, in their columns' types.
     *
     * @param  array  $rows
     * @return array
     */
    protected function castRows(array $rows): array
    {
        return is_array(reset($rows)) ? array_map([$this, 'castRow'], $rows) : $this->castRow($rows);
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
     * Pass a document id instead of a vector to find documents similar to that one.
     * Pair it with selectRaw('knn_dist() as distance') to read the similarity score.
     * Manticore has no KNN by UUID, so with a UUID id the select reads that document's vector
     * first and searches with it, leaving the document out of the results. Until then
     * toSql() shows the id; running the query rewrites it into the vector.
     *
     * @param  string  $column
     * @param  array<int, int|float>|int|string  $vector
     * @param  int  $k
     * @param  int|array<string, mixed>|null  $ef
     * @param  string  $boolean
     * @return static
     */
    public function knn(string $column, array|int|string $vector, int $k = 10, int|array|null $ef = null, string $boolean = 'and'): static
    {
        $this->wheres[] = [
            'type'    => 'Knn',
            'column'  => $column,
            'vector'  => is_array($vector)
                ? array_map(static fn ($value) => (float) $value, array_values($vector))
                : $vector,
            'k'       => $k,
            'options' => is_int($ef) ? ['ef' => $ef] : (array) $ef,
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

        $values = $this->castRows($values);

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

    /**
     * Partial REPLACE of one document: only the given fields are rewritten, the rest of
     * the document is kept. Unlike UPDATE it can change full-text fields. Needs Manticore
     * Buddy and every field stored; it filters by id only. On an id that does not exist
     * it creates a document holding just the given fields. Buddy only takes numeric ids, so
     * when the server rejects a UUID the merge is done here instead, which is not atomic.
     *
     * @param  int|string  $id
     * @param  array<string, mixed>  $values
     * @return bool
     *
     * @throws \InvalidArgumentException
     */
    public function replaceWhereId(int|string $id, array $values): bool
    {
        $id = DocumentId::normalize($id);

        if (empty($values)) {
            return true;
        }

        $this->applyBeforeQueryCallbacks();

        $values = $this->castRow($values);

        $bindings = $this->cleanBindings(array_values($values));
        $bindings[] = $id;

        try {
            return $this->connection->insert(
                $this->grammar->compileReplaceSet($this, $values),
                $bindings
            );
        } catch (QueryException $e) {
            if (! is_string($id) || ! str_contains($e->getMessage(), 'unexpected SET, expecting VALUES')) {
                throw $e;
            }
        }

        return $this->replaceMergingDocument($id, $values);
    }

    /**
     * What Buddy does for a numeric id: read the document, overlay the given fields and
     * REPLACE the whole of it. It has to read with SELECT *, because before Manticore 29.2.5
     * listing a text column by name on a UUID table returns it empty.
     *
     * @param  string  $id
     * @param  array<string, mixed>  $values
     * @return bool
     *
     * @throws \RuntimeException
     */
    protected function replaceMergingDocument(string $id, array $values): bool
    {
        $table = $this->connection->getTablePrefix() . $this->from;

        $types = [];

        foreach ($this->connection->select('DESC ' . $table) as $field) {
            if ($field->Type === 'text' && ! str_contains($field->Properties, 'stored')) {
                throw new RuntimeException(
                    "Cannot partially replace a document in [{$table}]: text field [{$field->Field}] is not stored."
                );
            }

            $types[$field->Field] = $field->Type;
        }

        $document = (array) $this->newQuery()->from($this->from)->where('id', $id)->first();

        foreach ($this->autoEmbeddedColumns($table) as $column) {
            unset($document[$column]);
        }

        foreach ($document as $column => $value) {
            if ($value === null || $value === '') {
                unset($document[$column]);
            } elseif (in_array($types[$column], ['mva', 'mva64', 'float_vector'], true)) {
                $document[$column] = new Expression($this->formatTuple($value, $types[$column]));
            }
        }

        return $this->newQuery()->from($this->from)->replace(array_merge($document, $values, ['id' => $id]));
    }

    /**
     * Vector columns the server fills from another field. They must stay out of a REPLACE,
     * since Manticore refuses a value for them and regenerates them from the new text.
     *
     * @param  string  $table
     * @return array<int, string>
     */
    protected function autoEmbeddedColumns(string $table): array
    {
        $definition = (array) ($this->connection->select('SHOW CREATE TABLE ' . $table)[0] ?? []);

        preg_match_all(
            '/^\s*`?(\w+)`?\s+float_vector\b.*\bmodel_name\s*=/im',
            (string) ($definition['Create Table'] ?? ''),
            $matches
        );

        return $matches[1];
    }

    /**
     * Turn a multi-value or vector attribute, which reads back as "a,b", into a tuple literal.
     * Every part is cast to a number, so inlining it is safe.
     *
     * @param  string  $value
     * @param  string  $type
     * @return string
     */
    protected function formatTuple(string $value, string $type): string
    {
        $parts = array_map(
            static fn (string $part) => $type === 'float_vector' ? var_export((float) $part, true) : (string) (int) $part,
            explode(',', $value)
        );

        return '(' . implode(', ', $parts) . ')';
    }

    /**
     * Keyset paging for chunkById() and lazyById(). Recent Laravel versions start it with
     * `id is not null`, which Manticore rejects; an id is never null, so it is left out.
     *
     * @param  int  $perPage
     * @param  int|string|null  $lastId
     * @param  string  $column
     * @return static
     */
    public function forPageAfterId($perPage = 15, $lastId = 0, $column = 'id')
    {
        $this->orders = $this->removeExistingOrdersFor($column);

        if (! is_null($lastId)) {
            $this->where($column, '>', $lastId);
        }

        return $this->orderBy($column, 'asc')->limit($perPage);
    }

    /**
     * Same as forPageAfterId(), walking the ids downwards.
     *
     * @param  int  $perPage
     * @param  int|string|null  $lastId
     * @param  string  $column
     * @return static
     */
    public function forPageBeforeId($perPage = 15, $lastId = 0, $column = 'id')
    {
        $this->orders = $this->removeExistingOrdersFor($column);

        if (! is_null($lastId)) {
            $this->where($column, '<', $lastId);
        }

        return $this->orderBy($column, 'desc')->limit($perPage);
    }

    /**
     * Run the select, unless knn() points at a document that has no vector to search with.
     *
     * @return array
     */
    protected function runSelect()
    {
        return $this->resolveKnnDocumentIds() ? parent::runSelect() : [];
    }

    /**
     * Manticore has no SELECT EXISTS(...), so fetch a single row instead.
     *
     * @return bool
     */
    public function exists()
    {
        return (clone $this)->limit(1)->get()->isNotEmpty();
    }

    /**
     * Get a lazy collection for the given query.
     *
     * @return \Illuminate\Support\LazyCollection
     */
    public function cursor()
    {
        return $this->resolveKnnDocumentIds() ? parent::cursor() : LazyCollection::empty();
    }

    /**
     * Swap each knn() by UUID for the vector of that document, and leave the document out of
     * the results, which is what Manticore does for a numeric id.
     *
     * @return bool  False when a document does not exist or has no vector.
     */
    protected function resolveKnnDocumentIds(): bool
    {
        foreach ($this->wheres as $index => $where) {
            if ($where['type'] !== 'Knn' || ! is_string($where['vector'])) {
                continue;
            }

            $id = DocumentId::normalize($where['vector']);

            if (! is_string($id)) {
                continue;
            }

            $vector = $this->newQuery()->from($this->from)->where('id', $id)->value($where['column']);

            if ($vector === null || $vector === '') {
                return false;
            }

            $this->wheres[$index]['vector'] = array_map('floatval', explode(',', $vector));
            $this->where('id', '!=', $id);
        }

        return true;
    }
}
