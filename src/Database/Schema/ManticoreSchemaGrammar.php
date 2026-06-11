<?php

namespace ManticoreEloquent\Database\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\Grammar;
use Illuminate\Support\Fluent;

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
     * @param  \Illuminate\Database\Schema\Blueprint  $blueprint
     * @param  \Illuminate\Support\Fluent  $command
     * @param  \Illuminate\Database\Connection|null  $connection
     * @return string
     */
    public function compileCreate(Blueprint $blueprint, Fluent $command, $connection = null): string
    {
        $columns = implode(', ', $this->getManticoreColumns($blueprint));

        $sql = sprintf('create table %s (%s)', $this->wrapTable($blueprint), $columns);

        $engine = $blueprint->engine ?? (($connection ?? $this->connection ?? null)?->getConfig('engine'));

        if (! empty($engine)) {
            $sql .= " engine='" . $engine . "'";
        }

        $options = $this->getManticoreTableOptions($blueprint);

        if ($options !== '') {
            $sql .= ' ' . $options;
        }

        return $sql;
    }

    /**
     * Collect table-level Manticore options (min_infix_len, morphology, …) declared via the
     * Blueprint macros, and render them as the trailing key='value' pairs of CREATE TABLE.
     *
     * @param  \Illuminate\Database\Schema\Blueprint  $blueprint
     * @return string
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
            $pairs[] = $key . "='" . str_replace("'", "\\'", (string) $value) . "'";
        }

        return implode(' ', $pairs);
    }

    /**
     * One ALTER per column — Manticore adds columns one at a time.
     *
     * @param  \Illuminate\Database\Schema\Blueprint  $blueprint
     * @param  \Illuminate\Support\Fluent  $command
     * @return array<int, string>
     */
    public function compileAdd(Blueprint $blueprint, Fluent $command): array
    {
        $table = $this->wrapTable($blueprint);

        return array_map(
            fn (string $column) => "alter table {$table} add column {$column}",
            $this->getManticoreColumns($blueprint)
        );
    }

    /**
     * Compile DROP TABLE.
     *
     * @param  \Illuminate\Database\Schema\Blueprint  $blueprint
     * @param  \Illuminate\Support\Fluent  $command
     * @return string
     */
    public function compileDrop(Blueprint $blueprint, Fluent $command): string
    {
        return 'drop table ' . $this->wrapTable($blueprint);
    }

    /**
     * Compile DROP TABLE IF EXISTS.
     *
     * @param  \Illuminate\Database\Schema\Blueprint  $blueprint
     * @param  \Illuminate\Support\Fluent  $command
     * @return string
     */
    public function compileDropIfExists(Blueprint $blueprint, Fluent $command): string
    {
        return 'drop table if exists ' . $this->wrapTable($blueprint);
    }

    /**
     * Compile one ALTER … DROP COLUMN per dropped column.
     *
     * @param  \Illuminate\Database\Schema\Blueprint  $blueprint
     * @param  \Illuminate\Support\Fluent  $command
     * @return array<int, string>
     */
    public function compileDropColumn(Blueprint $blueprint, Fluent $command): array
    {
        $table = $this->wrapTable($blueprint);

        return array_map(
            fn ($column) => "alter table {$table} drop column " . $this->wrap($column),
            $command->columns
        );
    }

    /**
     * Column definitions, minus the implicit `id` Manticore manages on its own.
     *
     * @param  \Illuminate\Database\Schema\Blueprint  $blueprint
     * @return array<int, string>
     */
    protected function getManticoreColumns(Blueprint $blueprint): array
    {
        $columns = [];

        foreach ($blueprint->getAddedColumns() as $column) {
            if (strtolower((string) $column->name) === 'id') {
                continue;
            }

            $columns[] = $this->wrap($column) . ' ' . $this->getType($column);
        }

        return $columns;
    }

    /**
     * Full-text searchable field. Defaults to Manticore's implicit `indexed stored`;
     * use ->indexed()/->stored() on the column to spell the options out explicitly.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
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
     * Plain string attribute (filterable/sortable, not full-text).
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function typeString(Fluent $column): string
    {
        return 'string';
    }

    /**
     * Char behaves like a string attribute.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function typeChar(Fluent $column): string
    {
        return 'string';
    }

    /**
     * 32-bit integer attribute.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function typeInteger(Fluent $column): string
    {
        return 'integer';
    }

    /**
     * Tiny integers collapse to a 32-bit integer.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function typeTinyInteger(Fluent $column): string
    {
        return 'integer';
    }

    /**
     * Small integers collapse to a 32-bit integer.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function typeSmallInteger(Fluent $column): string
    {
        return 'integer';
    }

    /**
     * Medium integers collapse to a 32-bit integer.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function typeMediumInteger(Fluent $column): string
    {
        return 'integer';
    }

    /**
     * 64-bit integer attribute.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function typeBigInteger(Fluent $column): string
    {
        return 'bigint';
    }

    /**
     * Floating-point attribute.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function typeFloat(Fluent $column): string
    {
        return 'float';
    }

    /**
     * Doubles are stored as float.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function typeDouble(Fluent $column): string
    {
        return 'float';
    }

    /**
     * Decimals are stored as float — Manticore has no fixed-precision type.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function typeDecimal(Fluent $column): string
    {
        return 'float';
    }

    /**
     * Boolean attribute.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function typeBoolean(Fluent $column): string
    {
        return 'bool';
    }

    /**
     * JSON document attribute.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function typeJson(Fluent $column): string
    {
        return 'json';
    }

    /**
     * JSONB maps to the same json type.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function typeJsonb(Fluent $column): string
    {
        return 'json';
    }

    /**
     * Stored as a Unix timestamp.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function typeTimestamp(Fluent $column): string
    {
        return 'timestamp';
    }

    /**
     * Datetimes are stored as a Unix timestamp.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function typeDateTime(Fluent $column): string
    {
        return 'timestamp';
    }

    /**
     * Dates are stored as a Unix timestamp.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function typeDate(Fluent $column): string
    {
        return 'timestamp';
    }

    /**
     * Multi-value attribute (uint set).
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function typeMva(Fluent $column): string
    {
        return 'multi';
    }

    /**
     * Multi-value attribute (bigint set).
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
     */
    protected function typeMva64(Fluent $column): string
    {
        return 'multi64';
    }

    /**
     * Float vector column for KNN / semantic search, with its knn_* options inline.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return string
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

        return implode(' ', $parts);
    }

    /**
     * Wrap an identifier in Manticore backticks, leaving * alone.
     *
     * @param  string  $value
     * @return string
     */
    protected function wrapValue($value): string
    {
        if ($value !== '*') {
            return '`' . str_replace('`', '``', $value) . '`';
        }

        return $value;
    }
}
