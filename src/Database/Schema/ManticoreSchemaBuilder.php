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
     * List a table's columns by reading DESC <table>.
     *
     * @param  string  $table
     * @return array<int, string>
     */
    public function getColumnListing($table): array
    {
        $table = $this->connection->getTablePrefix() . $table;

        $columns = [];

        foreach ($this->connection->select('DESC ' . $table) as $row) {
            $row = (array) $row;
            $columns[] = $row['Field'] ?? (string) reset($row);
        }

        return $columns;
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
