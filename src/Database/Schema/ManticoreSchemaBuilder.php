<?php

namespace ManticoreEloquent\Database\Schema;

use Illuminate\Database\Schema\Builder;

class ManticoreSchemaBuilder extends Builder
{
    /**
     * Manticore introspects through SHOW TABLES / DESC instead of information_schema,
     * so existence checks walk the SHOW TABLES output.
     *
     * @param  string  $table
     */
    public function hasTable($table): bool
    {
        $table = $this->connection->getTablePrefix().$table;

        foreach ($this->getTables() as $existing) {
            if (strcasecmp($existing['name'], $table) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * List the tables in Laravel's introspection shape.
     *
     * @param  mixed  $schema
     * @return array<int, array<string, mixed>>
     */
    public function getTables($schema = null): array
    {
        return array_map(static fn ($row) => [
            'name' => $row->Table,
            'schema' => null,
            'size' => null,
            'comment' => null,
            'collation' => null,
            'engine' => $row->Type,
        ], $this->connection->select('SHOW TABLES'));
    }

    /**
     * Manticore has no foreign keys.
     *
     * @param  string  $table
     * @return array<int, array<string, mixed>>
     */
    public function getForeignKeys($table): array
    {
        return [];
    }

    /**
     * Manticore has no views.
     *
     * @param  mixed  $schema
     * @return array<int, array<string, mixed>>
     */
    public function getViews($schema = null): array
    {
        return [];
    }

    /**
     * Manticore has no foreign keys to turn off.
     *
     * @return bool
     */
    public function disableForeignKeyConstraints()
    {
        return true;
    }

    /**
     * Manticore has no foreign keys to turn on.
     *
     * @return bool
     */
    public function enableForeignKeyConstraints()
    {
        return true;
    }

    /**
     * List a table's column names.
     *
     * @param  string  $table
     * @return array<int, string>
     */
    public function getColumnListing($table): array
    {
        return array_column($this->getColumns($table), 'name');
    }

    /**
     * Describe a table's columns in Laravel's introspection shape, read from DESC.
     *
     * @param  string  $table
     * @return array<int, array<string, mixed>>
     */
    public function getColumns($table): array
    {
        $table = $this->connection->getTablePrefix().$table;

        return array_map(static fn ($row) => [
            'name' => $row->Field,
            'type_name' => $row->Type,
            'type' => $row->Type,
            'collation' => null,
            'nullable' => false,
            'default' => null,
            'auto_increment' => $row->Field === 'id',
            'comment' => $row->Properties !== '' ? $row->Properties : null,
            'generation' => null,
        ], $this->connection->select('DESC '.$table));
    }

    /**
     * Describe a table's indexes in Laravel's introspection shape.
     *
     * @param  string  $table
     * @return array<int, array<string, mixed>>
     */
    public function getIndexes($table): array
    {
        $table = $this->connection->getTablePrefix().$table;

        $fullText = [];

        $indexes = [[
            'name' => 'primary',
            'columns' => ['id'],
            'type' => 'primary',
            'unique' => true,
            'primary' => true,
        ]];

        foreach ($this->connection->select('DESC '.$table) as $row) {
            if ($row->Type === 'text' && str_contains($row->Properties, 'indexed')) {
                $fullText[] = $row->Field;
            }

            if (in_array($row->Type, ['float_vector', 'float_vector_array'], true) && str_contains($row->Properties, 'knn')) {
                $indexes[] = [
                    'name' => $row->Field.'_knn',
                    'columns' => [$row->Field],
                    'type' => 'knn',
                    'unique' => false,
                    'primary' => false,
                ];
            }
        }

        if ($fullText !== []) {
            $indexes[] = [
                'name' => 'fulltext',
                'columns' => $fullText,
                'type' => 'fulltext',
                'unique' => false,
                'primary' => false,
            ];
        }

        return $indexes;
    }

    /**
     * Drop every index reported by SHOW TABLES.
     */
    public function dropAllTables(): void
    {
        foreach ($this->getTables() as $table) {
            $this->connection->statement('DROP TABLE IF EXISTS '.$table['name']);
        }
    }
}
