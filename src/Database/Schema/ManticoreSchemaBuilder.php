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
     * @return bool
     */
    public function hasTable($table): bool
    {
        $table = $this->connection->getTablePrefix() . $table;

        foreach ($this->connection->select('SHOW TABLES') as $row) {
            foreach ((array) $row as $value) {
                if (strcasecmp((string) $value, $table) === 0) {
                    return true;
                }
            }
        }

        return false;
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
        $table = $this->connection->getTablePrefix() . $table;

        $columns = [];

        foreach ($this->connection->select('DESC ' . $table) as $row) {
            $row = (array) $row;

            $name       = (string) ($row['Field'] ?? reset($row));
            $type       = strtolower((string) ($row['Type'] ?? ''));
            $properties = trim((string) ($row['Properties'] ?? ''));

            $columns[] = [
                'name'           => $name,
                'type_name'      => $type,
                'type'           => $type,
                'collation'      => null,
                'nullable'       => false,
                'default'        => null,
                'auto_increment' => strtolower($name) === 'id',
                'comment'        => $properties !== '' ? $properties : null,
                'generation'     => null,
            ];
        }

        return $columns;
    }

    /**
     * Describe a table's indexes in Laravel's introspection shape.
     *
     * @param  string  $table
     * @return array<int, array<string, mixed>>
     */
    public function getIndexes($table): array
    {
        $table = $this->connection->getTablePrefix() . $table;

        $fullText = [];

        $indexes = [[
            'name'    => 'primary',
            'columns' => ['id'],
            'type'    => 'primary',
            'unique'  => true,
            'primary' => true,
        ]];

        foreach ($this->connection->select('DESC ' . $table) as $row) {
            $row = (array) $row;

            $name       = (string) ($row['Field'] ?? reset($row));
            $type       = strtolower((string) ($row['Type'] ?? ''));
            $properties = strtolower((string) ($row['Properties'] ?? ''));

            if ($type === 'text' && str_contains($properties, 'indexed')) {
                $fullText[] = $name;
            }

            if ($type === 'float_vector' && str_contains($properties, 'knn')) {
                $indexes[] = [
                    'name'    => $name . '_knn',
                    'columns' => [$name],
                    'type'    => 'knn',
                    'unique'  => false,
                    'primary' => false,
                ];
            }
        }

        if ($fullText !== []) {
            $indexes[] = [
                'name'    => 'fulltext',
                'columns' => $fullText,
                'type'    => 'fulltext',
                'unique'  => false,
                'primary' => false,
            ];
        }

        return $indexes;
    }

    /**
     * Drop every index reported by SHOW TABLES.
     *
     * @return void
     */
    public function dropAllTables(): void
    {
        foreach ($this->connection->select('SHOW TABLES') as $row) {
            $row  = (array) $row;
            $name = $row['Index'] ?? $row['Table'] ?? (string) reset($row);

            $this->connection->statement('DROP TABLE IF EXISTS ' . $name);
        }
    }
}
