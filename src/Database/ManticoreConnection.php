<?php

namespace ManticoreEloquent\Database;

use DateTimeInterface;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\QueryException;
use ManticoreEloquent\Database\Query\Grammars\ManticoreQueryGrammar;
use ManticoreEloquent\Database\Query\ManticoreQueryBuilder;
use ManticoreEloquent\Database\Query\Processors\ManticoreProcessor;
use ManticoreEloquent\Database\Schema\ManticoreSchemaBuilder;
use ManticoreEloquent\Database\Schema\ManticoreSchemaGrammar;

class ManticoreConnection extends MySqlConnection
{
    /**
     * Column types read from DESC, by table.
     *
     * @var array<string, array<string, string>>
     */
    protected array $columnTypes = [];

    /**
     * The type of each column of a table, as DESC reports it. Empty when it is not a single
     * table that exists, which leaves the server to answer whatever is asked of it.
     *
     * @param  string  $table
     * @return array<string, string>
     */
    public function columnTypes(string $table): array
    {
        $table = $this->getTablePrefix() . $table;

        if (isset($this->columnTypes[$table])) {
            return $this->columnTypes[$table];
        }

        if (! preg_match('/^\w+$/', $table)) {
            return [];
        }

        try {
            $columns = $this->select('DESC ' . $table);
        } catch (QueryException) {
            return [];
        }

        return $this->columnTypes[$table] = array_column($columns, 'Type', 'Field');
    }

    /**
     * Forget the known column types when a statement can change them.
     *
     * @param  string  $query
     * @param  array  $bindings
     * @return bool
     */
    public function statement($query, $bindings = [])
    {
        if (preg_match('/^\s*(create|alter|drop)\b/i', $query)) {
            $this->columnTypes = [];
        }

        return parent::statement($query, $bindings);
    }

    /**
     * A timestamp column holds a Unix integer, so a date is sent as one. As a date string it
     * would be rejected by UPDATE, and stored as 0 by INSERT.
     *
     * @param  array  $bindings
     * @return array
     */
    public function prepareBindings(array $bindings)
    {
        foreach ($bindings as $key => $value) {
            if ($value instanceof DateTimeInterface) {
                $bindings[$key] = $value->getTimestamp();
            }
        }

        return parent::prepareBindings($bindings);
    }

    /**
     * Use the Manticore query grammar in place of the MySQL one.
     *
     * @return \ManticoreEloquent\Database\Query\Grammars\ManticoreQueryGrammar
     */
    protected function getDefaultQueryGrammar(): ManticoreQueryGrammar
    {
        return $this->withManticoreTablePrefix(new ManticoreQueryGrammar($this));
    }

    /**
     * Use the Manticore schema grammar in place of the MySQL one.
     *
     * @return \ManticoreEloquent\Database\Schema\ManticoreSchemaGrammar
     */
    protected function getDefaultSchemaGrammar(): ManticoreSchemaGrammar
    {
        return $this->withManticoreTablePrefix(new ManticoreSchemaGrammar($this));
    }

    /**
     * Laravel 12 dropped Connection::withTablePrefix() — grammars now read the prefix
     * straight from their connection. We only call it on the versions that still have it.
     *
     * @template TGrammar of \Illuminate\Database\Grammar
     * @param  TGrammar  $grammar
     * @return TGrammar
     */
    protected function withManticoreTablePrefix($grammar)
    {
        return method_exists($this, 'withTablePrefix')
            ? $this->withTablePrefix($grammar)
            : $grammar;
    }

    /**
     * Hand back a schema builder that introspects Manticore via SHOW TABLES / DESC.
     *
     * @return \ManticoreEloquent\Database\Schema\ManticoreSchemaBuilder
     */
    public function getSchemaBuilder(): ManticoreSchemaBuilder
    {
        if ($this->schemaGrammar === null) {
            $this->useDefaultSchemaGrammar();
        }

        return new ManticoreSchemaBuilder($this);
    }

    /**
     * Use the Manticore result post-processor.
     *
     * @return \ManticoreEloquent\Database\Query\Processors\ManticoreProcessor
     */
    protected function getDefaultPostProcessor(): ManticoreProcessor
    {
        return new ManticoreProcessor;
    }

    /**
     * Start queries on the Manticore-aware builder, so models inherit match()/option()/facet().
     *
     * @return \ManticoreEloquent\Database\Query\ManticoreQueryBuilder
     */
    public function query(): ManticoreQueryBuilder
    {
        return new ManticoreQueryBuilder(
            $this,
            $this->getQueryGrammar(),
            $this->getPostProcessor()
        );
    }
}
