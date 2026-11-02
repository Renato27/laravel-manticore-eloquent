<?php

namespace ManticoreEloquent\Database\Query;

use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;
use LogicException;
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
     * Add a full-text MATCH() condition, written in Manticore's search syntax. For text typed by
     * a user, use matchText(). Pass a $field to scope it (e.g. 'title'), or leave it null to
     * search every field. Manticore takes one MATCH(), so several calls are combined into it.
     */
    public function match(string $query, ?string $field = null, string $boolean = 'and'): static
    {
        $expression = $field !== null && $field !== '' && $field !== '*'
            ? '@'.$field.' '.$query
            : $query;

        $where = ['type' => 'Match', 'value' => $expression, 'boolean' => $boolean];

        if ($boolean !== 'and') {
            $this->wheres[] = $where;
            $this->addBinding($expression, 'where');
        } elseif (($this->wheres[0]['type'] ?? null) === 'Match') {
            $this->wheres[0]['value'] = '('.$this->wheres[0]['value'].') ('.$expression.')';
            $this->bindings['where'][0] = $this->wheres[0]['value'];
        } else {
            array_unshift($this->wheres, $where);
            array_unshift($this->bindings['where'], $expression);
        }

        return $this;
    }

    /**
     * Add a full-text MATCH() condition for plain words, such as text typed by a user: nothing
     * in it is read as search syntax.
     */
    public function matchText(string $text, ?string $field = null, string $boolean = 'and'): static
    {
        return $this->match(addcslashes($text, '\\()|-!@~"&/^$=<'), $field, $boolean);
    }

    /**
     * Add a full-text condition on one or several fields. Manticore has it as MATCH().
     *
     * @param  string|array  $columns
     * @param  string  $value
     * @param  string  $boolean
     * @return static
     */
    public function whereFullText($columns, $value, array $options = [], $boolean = 'and')
    {
        $columns = (array) $columns;

        return $this->matchText($value, count($columns) > 1 ? '('.implode(',', $columns).')' : $columns[0], $boolean);
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

            if ($this->invalidOperator($operator)) {
                [$value, $operator] = [$operator, '='];
            }

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
     * @return bool
     */
    public function insert(array $values)
    {
        return parent::insert($this->castRows($values));
    }

    /**
     * Insert a row and get its id, with the values in their columns' types.
     *
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
     * @return int
     */
    public function update(array $values)
    {
        $this->requireWhere();

        return parent::update($this->castRow($values));
    }

    /**
     * Delete documents.
     *
     * @param  mixed  $id
     * @return int
     */
    public function delete($id = null)
    {
        if ($id === null) {
            $this->requireWhere();
        }

        return parent::delete($id);
    }

    /**
     * Manticore's UPDATE has no LIMIT, so this updates every document that matches, where
     * Laravel stops at the first.
     *
     * @return bool
     */
    public function updateOrInsert(array $attributes, array|callable $values = [])
    {
        $exists = $this->where($attributes)->exists();

        if ($values instanceof Closure) {
            $values = $values($exists);
        }

        if (! $exists) {
            return $this->insert(array_merge($attributes, $values));
        }

        return empty($values) || $this->update($values);
    }

    /**
     * UPDATE and DELETE need a WHERE on Manticore, so a query meant for every document gets one
     * that matches them all. A UUID id only takes = and !=, so it is compared with a new UUID.
     */
    protected function requireWhere(): void
    {
        if (empty($this->wheres)) {
            $this->columnType('id') === 'uuid'
                ? $this->where('id', '!=', (string) Str::uuid())
                : $this->where('id', '>', 0);
        }
    }

    /**
     * Put a value in the type of its column. Manticore does not convert reliably: a filter takes a
     * quoted number in some forms and rejects it in others, such as >=, IN, BETWEEN or a float,
     * UPDATE skips it without an error, a string column drops an unquoted one, and a timestamp column
     * misreads a quoted value. ServerLimitsTest shows each case. PDO quotes anything but an int, so
     * a float is inlined, which is safe since it has been cast to one.
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
     */
    public function columnType(mixed $column): ?string
    {
        if (! is_string($column) || ! is_string($this->from)) {
            return null;
        }

        $names = preg_split('/\s+as\s+/i', $this->from);

        if (! empty($this->joins) && preg_match('/^(\w+)\.(\w+)$/', $column, $qualified)) {
            return $this->connection->columnTypes($qualified[1])[$qualified[2]] ?? null;
        }

        return $this->connection->columnTypes($names[0])[Str::after($column, last($names).'.')] ?? null;
    }

    /**
     * Put one row in its columns' types. Manticore has no NULL to write, so a null becomes the
     * empty value of its column, and a list goes out as a tuple, as does the "a,b" form it reads
     * back as.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function castRow(array $row): array
    {
        foreach ($row as $column => $value) {
            if ($value === null) {
                $row[$column] = $this->emptyValue($column);
            } elseif (is_array($value) || (is_string($value) && $this->isListColumn($column))) {
                $row[$column] = $this->isListColumn($column) ? $this->formatTuple($value, $this->columnType($column)) : $value;
            } else {
                $row[$column] = $this->castToColumn($column, $value);
            }
        }

        return $row;
    }

    /**
     * Whether a column holds a list of values.
     */
    protected function isListColumn(string $column): bool
    {
        return in_array($this->columnType($column), ['mva', 'mva64', 'float_vector'], true);
    }

    /**
     * The value of a column that has none.
     */
    protected function emptyValue(string $column): mixed
    {
        return match ($this->columnType($column)) {
            'uint', 'bigint', 'float', 'bool', 'timestamp' => 0,
            'string', 'text', 'json' => '',
            'mva', 'mva64', 'float_vector' => new Expression('()'),
            default => null,
        };
    }

    /**
     * Put a row, or a list of rows, in their columns' types.
     */
    protected function castRows(array $rows): array
    {
        return is_array(reset($rows)) ? array_map([$this, 'castRow'], $rows) : $this->castRow($rows);
    }

    /**
     * Insert documents or rewrite the ones that exist, as a REPLACE. It has no ON DUPLICATE KEY, as
     * ServerLimitsTest shows, and REPLACE rewrites the whole document by id, so it cannot honor
     * anything less.
     *
     * @param  array|string  $uniqueBy
     * @param  array|null  $update
     * @return int
     *
     * @throws LogicException
     */
    public function upsert(array $values, $uniqueBy, $update = null)
    {
        if (empty($values)) {
            return 0;
        }

        $rows = is_array(reset($values)) ? $values : [$values];
        $columns = array_keys(reset($rows));

        if ((array) $uniqueBy !== ['id'] || array_diff($columns, $update ?? $columns, ['id']) !== []) {
            throw new LogicException(
                'Manticore can only run upsert() as a REPLACE, keyed by id and writing every column. '
                .'To change some columns, use update(), or replaceWhereId() for one document.'
            );
        }

        $this->replace($rows);

        return count($rows);
    }

    /**
     * Manticore's UPDATE takes values, not expressions, so this could only be a read and a write,
     * which would lose increments made in between. ServerLimitsTest shows it.
     *
     * @return int
     *
     * @throws LogicException
     */
    public function incrementEach(array $columns, array $extra = [])
    {
        throw new LogicException('Manticore has no atomic increment(): its UPDATE takes values, not expressions. Read the value and write the new one with update().');
    }

    /**
     * See incrementEach().
     *
     * @return int
     *
     * @throws LogicException
     */
    public function decrementEach(array $columns, array $extra = [])
    {
        throw new LogicException('Manticore has no atomic decrement(): its UPDATE takes values, not expressions. Read the value and write the new one with update().');
    }

    /**
     * Run the select and read its facets, which come back as extra result sets. Each facet is
     * named by its alias or its field, and holds the field and a count(*).
     *
     * @return array{rows: Collection, facets: array<string, Collection>}
     */
    public function getWithFacets(array $columns = ['*']): array
    {
        $query = clone $this;
        $query->columns ??= $columns;

        $sets = $this->connection->selectResultSets($query->toSql(), $query->getBindings());

        $rows = collect($this->processor->processSelect($this, array_shift($sets)));

        $names = array_map(
            static fn (string $facet) => last(preg_split('/\s+as\s+/i', $facet)),
            $this->options['__facets'] ?? []
        );

        return ['rows' => $rows, 'facets' => array_combine($names, array_map('collect', $sets))];
    }

    /**
     * Set a single Manticore query OPTION.
     */
    public function option(string $key, mixed $value): static
    {
        $this->options[$key] = $value;

        return $this;
    }

    /**
     * Shortcut for the max_matches OPTION.
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
     * first and searches with it, leaving the document out of the results. toSql() shows the id.
     *
     * @param  array<int, int|float>|int|string  $vector
     * @param  int|array<string, mixed>|null  $ef
     */
    public function knn(string $column, array|int|string $vector, int $k = 10, int|array|null $ef = null, string $boolean = 'and'): static
    {
        $this->wheres[] = [
            'type' => 'Knn',
            'column' => $column,
            'vector' => is_array($vector)
                ? array_map(static fn ($value) => (float) $value, array_values($vector))
                : $vector,
            'k' => $k,
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
     * @param  array<string, mixed>  $options
     */
    public function highlight(?string $field = null, array $options = [], string $alias = 'highlight'): static
    {
        if (is_null($this->columns)) {
            $this->columns = ['*'];
        }

        $this->columns[] = new Expression(
            $this->grammar->compileHighlight($field, $options).' as '.$this->grammar->wrap($alias)
        );

        return $this;
    }

    /**
     * Add a FACET clause. get() ignores the result, and getWithFacets() returns it.
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
     * @param  array<string, mixed>  $values
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

        $bindings = $this->cleanBindings($values);
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
     * @param  array<string, mixed>  $values
     *
     * @throws RuntimeException
     */
    protected function replaceMergingDocument(string $id, array $values): bool
    {
        $table = $this->connection->getTablePrefix().$this->from;

        foreach ($this->connection->select('DESC '.$table) as $field) {
            if ($field->Type === 'text' && ! str_contains($field->Properties, 'stored')) {
                throw new RuntimeException(
                    "Cannot partially replace a document in [{$table}]: text field [{$field->Field}] is not stored."
                );
            }
        }

        $document = (array) $this->newQuery()->from($this->from)->where('id', $id)->first();

        foreach ($this->autoEmbeddedColumns($table) as $column) {
            unset($document[$column]);
        }

        return $this->newQuery()->from($this->from)->replace(array_merge($document, $values, ['id' => $id]));
    }

    /**
     * Vector columns the server fills from another field. They must stay out of a REPLACE,
     * since Manticore refuses a value for them and regenerates them from the new text.
     *
     * @return array<int, string>
     */
    protected function autoEmbeddedColumns(string $table): array
    {
        $definition = (array) ($this->connection->select('SHOW CREATE TABLE '.$table)[0] ?? []);

        preg_match_all(
            '/^\s*`?(\w+)`?\s+float_vector\b.*\bmodel_name\s*=/im',
            $definition['Create Table'] ?? '',
            $matches
        );

        return $matches[1];
    }

    /**
     * A list as a tuple literal. Every part is cast to a number, so inlining it is safe.
     *
     * @param  array<int, mixed>|string  $value
     */
    protected function formatTuple(array|string $value, string $type): Expression
    {
        $parts = is_array($value) ? $value : array_filter(explode(',', $value), static fn (string $part) => $part !== '');

        return new Expression('('.implode(', ', array_map(
            static fn ($part) => $type === 'float_vector' ? var_export((float) $part, true) : (string) (int) $part,
            $parts
        )).')');
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
     * Run the select. A knn() by UUID runs on a copy holding the vector of that document, or gives
     * no rows when it has none.
     *
     * @return array
     */
    protected function runSelect()
    {
        if (! $this->hasKnnByUuid()) {
            return parent::runSelect();
        }

        $query = clone $this;

        return $query->resolveKnnDocumentIds() ? $query->runSelect() : [];
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
     * Get a lazy collection for the given query, as runSelect() does for a knn() by UUID.
     *
     * @return LazyCollection
     */
    public function cursor()
    {
        if (! empty($this->joins)) {
            return parent::cursor()->map(fn ($row) => $this->processor->processSelect($this, [$row])[0]);
        }

        if (! $this->hasKnnByUuid()) {
            return parent::cursor();
        }

        $query = clone $this;

        return $query->resolveKnnDocumentIds() ? $query->cursor() : LazyCollection::empty();
    }

    /**
     * Count the rows. Manticore's COUNT(*) on a LEFT JOIN counts the documents of the left table,
     * not the joined rows, so those are counted one by one.
     *
     * @param  mixed  $columns
     * @return int
     */
    public function count($columns = '*')
    {
        return $this->hasLeftJoin() && $columns === '*' ? $this->get()->count() : parent::count($columns);
    }

    /**
     * Manticore's outer select takes no aggregate, so a grouped query is counted by its rows, as
     * is a LEFT JOIN, for the reason in count().
     *
     * @see https://manual.manticoresearch.com/Searching/Sub-selects
     *
     * @param  array  $columns
     * @return array
     */
    protected function runPaginationCountQuery($columns = ['*'])
    {
        if (! $this->groups && ! $this->havings && ! $this->hasLeftJoin()) {
            return parent::runPaginationCountQuery($columns);
        }

        $rows = $this->cloneWithout(['limit', 'offset'])->get();

        return [(object) ['aggregate' => $rows->count()]];
    }

    /**
     * Whether the query has a LEFT JOIN.
     */
    protected function hasLeftJoin(): bool
    {
        foreach ((array) $this->joins as $join) {
            if ($join->type === 'left') {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a knn() points at a document by UUID, which Manticore cannot do.
     */
    protected function hasKnnByUuid(): bool
    {
        foreach ($this->wheres as $where) {
            if ($where['type'] === 'Knn' && is_string($where['vector']) && is_string(DocumentId::normalize($where['vector']))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Swap each knn() by UUID for the vector of that document, and leave the document out of
     * the results, which is what Manticore does for a numeric id.
     *
     * @return bool False when a document does not exist or has no vector.
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
