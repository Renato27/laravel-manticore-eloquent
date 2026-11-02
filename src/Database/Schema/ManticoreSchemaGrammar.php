<?php

namespace ManticoreEloquent\Database\Schema;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\Grammar;
use Illuminate\Support\Fluent;
use LogicException;

class ManticoreSchemaGrammar extends Grammar
{
    /**
     * Manticore columns take no modifiers (no nullable/default/auto-increment).
     *
     * @var string[]
     */
    protected $modifiers = [];

    /**
     * Compile CREATE TABLE. The optional $connection keeps one signature valid across
     * Laravel 10/11 (which passes it in) and Laravel 12 (where the grammar holds its own).
     *
     * @param  Connection|null  $connection
     */
    public function compileCreate(Blueprint $blueprint, Fluent $command, $connection = null): string
    {
        // A table needs a column to be declared, and the id is the one that is always there.
        $columns = implode(', ', $this->getManticoreColumns($blueprint) ?: ['`id` bigint']);

        $sql = sprintf('create table %s (%s)', $this->wrapTable($blueprint), $columns);

        $engine = $blueprint->engine ?? (($connection ?? $this->connection ?? null)?->getConfig('engine'));

        if (! empty($engine)) {
            $sql .= " engine='".$engine."'";
        }

        $options = $this->getManticoreTableOptions($blueprint);

        if ($options !== '') {
            $sql .= ' '.$options;
        }

        return $sql;
    }

    /**
     * Collect table-level Manticore options (min_infix_len, morphology, …) declared via the
     * Blueprint macros, and render them as the trailing key='value' pairs of CREATE TABLE.
     */
    protected function getManticoreTableOptions(Blueprint $blueprint): string
    {
        $options = [];

        foreach ($blueprint->getCommands() as $command) {
            if ($command->name === 'manticoreTableOptions') {
                $options = array_merge($options, $command->options ?? []);
            }
        }

        if (empty($options)) {
            return '';
        }

        $pairs = [];

        foreach ($options as $key => $value) {
            $pairs[] = $key."='".str_replace("'", "\\'", (string) $value)."'";
        }

        return implode(' ', $pairs);
    }

    /**
     * One ALTER per column — Manticore adds columns one at a time.
     *
     * @return array<int, string>
     */
    public function compileAdd(Blueprint $blueprint, Fluent $command): array
    {
        $table = $this->wrapTable($blueprint);

        // Laravel 10 runs one command for all the added columns, and later versions one for each.
        $columns = isset($command->column)
            ? [$this->wrap($command->column).' '.$this->getType($command->column)]
            : $this->getManticoreColumns($blueprint);

        return array_map(fn (string $column) => "alter table {$table} add column {$column}", $columns);
    }

    /**
     * Compile DROP TABLE.
     */
    public function compileDrop(Blueprint $blueprint, Fluent $command): string
    {
        return 'drop table '.$this->wrapTable($blueprint);
    }

    /**
     * Compile DROP TABLE IF EXISTS.
     */
    public function compileDropIfExists(Blueprint $blueprint, Fluent $command): string
    {
        return 'drop table if exists '.$this->wrapTable($blueprint);
    }

    /**
     * Compile a table rename.
     */
    public function compileRename(Blueprint $blueprint, Fluent $command): string
    {
        return 'alter table '.$this->wrapTable($blueprint).' rename '.$this->wrapTable($command->to);
    }

    /**
     * Manticore can only change a column from int to bigint.
     *
     * @see https://manual.manticoresearch.com/Updating_table_schema_and_settings
     *
     * @return array<int, string>
     *
     * @throws LogicException
     */
    public function compileChange(Blueprint $blueprint, Fluent $command, $connection = null)
    {
        // Laravel 10 runs one command for all the changed columns, and later versions one for each.
        $columns = isset($command->column) ? [$command->column] : $blueprint->getChangedColumns();

        return array_map(function ($column) use ($blueprint) {
            if ($column->type !== 'bigInteger') {
                throw new LogicException("Manticore can only change a column to bigInteger, so it cannot change [{$column->name}] to [{$column->type}].");
            }

            return 'alter table '.$this->wrapTable($blueprint).' modify column '.$this->wrap($column).' bigint';
        }, $columns);
    }

    /**
     * Manticore has no foreign keys to declare, so the column stays and the constraint is left out,
     * as with indexes.
     *
     * @return null
     */
    public function compileForeign(Blueprint $blueprint, Fluent $command)
    {
        return null;
    }

    /**
     * See compileForeign().
     *
     * @return null
     */
    public function compileDropForeign(Blueprint $blueprint, Fluent $command)
    {
        return null;
    }

    /**
     * A text field is already full-text indexed.
     *
     * @see https://manual.manticoresearch.com/Creating_a_table/Data_types
     *
     * @return null
     */
    public function compileFulltext(Blueprint $blueprint, Fluent $command)
    {
        return null;
    }

    /**
     * See compileFulltext().
     *
     * @return null
     */
    public function compileDropFullText(Blueprint $blueprint, Fluent $command)
    {
        return null;
    }

    /**
     * Compile one ALTER … DROP COLUMN per dropped column.
     *
     * @return array<int, string>
     */
    public function compileDropColumn(Blueprint $blueprint, Fluent $command): array
    {
        $table = $this->wrapTable($blueprint);

        return array_map(
            fn ($column) => "alter table {$table} drop column ".$this->wrap($column),
            $command->columns
        );
    }

    /**
     * Column definitions, minus the implicit `id` Manticore manages on its own. A UUID id
     * has to be declared, since that is what makes the table use UUID document ids.
     *
     * @return array<int, string>
     */
    protected function getManticoreColumns(Blueprint $blueprint): array
    {
        $columns = [];

        foreach ($blueprint->getAddedColumns() as $column) {
            if (strtolower((string) $column->name) === 'id' && $column->type !== 'uuid') {
                continue;
            }

            $columns[] = $this->wrap($column).' '.$this->getType($column);
        }

        return $columns;
    }

    /**
     * The definition of a column, or a clear error when Manticore has no type for it.
     *
     * @see https://manual.manticoresearch.com/Creating_a_table/Data_types
     *
     * @return string
     *
     * @throws LogicException
     */
    protected function getType(Fluent $column)
    {
        if (! method_exists($this, 'type'.ucfirst($column->type))) {
            throw new LogicException("Manticore has no column type for [{$column->type}], used by column [{$column->name}].");
        }

        return parent::getType($column);
    }

    /**
     * Full-text searchable field. Defaults to Manticore's implicit `indexed stored`;
     * use ->indexed()/->stored() on the column to spell the options out explicitly.
     */
    protected function typeText(Fluent $column): string
    {
        $parts = ['text'];

        if (! empty($column->indexed)) {
            $parts[] = 'indexed';
        }

        if (! empty($column->stored)) {
            $parts[] = 'stored';
        }

        return implode(' ', $parts);
    }

    /**
     * UUID document id. Manticore only allows this type on the `id` column and rejects it
     * anywhere else.
     */
    protected function typeUuid(Fluent $column): string
    {
        return 'uuid';
    }

    /**
     * Plain string attribute (filterable/sortable, not full-text).
     */
    protected function typeString(Fluent $column): string
    {
        return 'string';
    }

    /**
     * Char behaves like a string attribute.
     */
    protected function typeChar(Fluent $column): string
    {
        return 'string';
    }

    /**
     * 32-bit integer attribute.
     */
    protected function typeInteger(Fluent $column): string
    {
        return 'integer';
    }

    /**
     * Tiny integers collapse to a 32-bit integer.
     */
    protected function typeTinyInteger(Fluent $column): string
    {
        return 'integer';
    }

    /**
     * Small integers collapse to a 32-bit integer.
     */
    protected function typeSmallInteger(Fluent $column): string
    {
        return 'integer';
    }

    /**
     * Medium integers collapse to a 32-bit integer.
     */
    protected function typeMediumInteger(Fluent $column): string
    {
        return 'integer';
    }

    /**
     * 64-bit integer attribute.
     */
    protected function typeBigInteger(Fluent $column): string
    {
        return 'bigint';
    }

    /**
     * Floating-point attribute.
     */
    protected function typeFloat(Fluent $column): string
    {
        return 'float';
    }

    /**
     * Doubles are stored as float.
     */
    protected function typeDouble(Fluent $column): string
    {
        return 'float';
    }

    /**
     * Decimals are stored as float — Manticore has no fixed-precision type.
     */
    protected function typeDecimal(Fluent $column): string
    {
        return 'float';
    }

    /**
     * Boolean attribute.
     */
    protected function typeBoolean(Fluent $column): string
    {
        return 'bool';
    }

    /**
     * JSON document attribute.
     */
    protected function typeJson(Fluent $column): string
    {
        return 'json';
    }

    /**
     * JSONB maps to the same json type.
     */
    protected function typeJsonb(Fluent $column): string
    {
        return 'json';
    }

    /**
     * Stored as a Unix timestamp.
     */
    protected function typeTimestamp(Fluent $column): string
    {
        return 'timestamp';
    }

    /**
     * Datetimes are stored as a Unix timestamp.
     */
    protected function typeDateTime(Fluent $column): string
    {
        return 'timestamp';
    }

    /**
     * Dates are stored as a Unix timestamp.
     */
    protected function typeDate(Fluent $column): string
    {
        return 'timestamp';
    }

    /**
     * Multi-value attribute (uint set).
     */
    protected function typeMva(Fluent $column): string
    {
        return 'multi';
    }

    /**
     * Multi-value attribute (bigint set).
     */
    protected function typeMva64(Fluent $column): string
    {
        return 'multi64';
    }

    /**
     * Float vector column for KNN / semantic search, with its knn_* options inline.
     */
    protected function typeFloatVector(Fluent $column): string
    {
        $parts = ['float_vector'];

        if (isset($column->knnType)) {
            $parts[] = "knn_type='{$column->knnType}'";
        }

        if (isset($column->dims)) {
            $parts[] = "knn_dims='{$column->dims}'";
        }

        if (isset($column->similarity)) {
            $parts[] = "hnsw_similarity='{$column->similarity}'";
        }

        foreach ((array) ($column->knnOptions ?? []) as $key => $value) {
            $parts[] = $key."='".$value."'";
        }

        return implode(' ', $parts);
    }

    protected function typeTinyText(Fluent $column): string
    {
        return $this->typeText($column);
    }

    protected function typeMediumText(Fluent $column): string
    {
        return $this->typeText($column);
    }

    protected function typeLongText(Fluent $column): string
    {
        return $this->typeText($column);
    }

    protected function typeEnum(Fluent $column): string
    {
        return 'string';
    }

    protected function typeSet(Fluent $column): string
    {
        return 'string';
    }

    protected function typeIpAddress(Fluent $column): string
    {
        return 'string';
    }

    protected function typeMacAddress(Fluent $column): string
    {
        return 'string';
    }

    /**
     * Manticore has no type for a time of day, so it stays as written.
     *
     * @see https://manual.manticoresearch.com/Creating_a_table/Data_types
     */
    protected function typeTime(Fluent $column): string
    {
        return 'string';
    }

    protected function typeTimeTz(Fluent $column): string
    {
        return 'string';
    }

    protected function typeYear(Fluent $column): string
    {
        return 'integer';
    }

    protected function typeTimestampTz(Fluent $column): string
    {
        return 'timestamp';
    }

    protected function typeDateTimeTz(Fluent $column): string
    {
        return 'timestamp';
    }

    /**
     * Wrap an identifier in Manticore backticks, leaving * alone.
     *
     * @param  string  $value
     */
    protected function wrapValue($value): string
    {
        if ($value !== '*') {
            return '`'.str_replace('`', '``', $value).'`';
        }

        return $value;
    }
}
