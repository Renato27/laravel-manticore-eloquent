<?php

namespace ManticoreEloquent\Database;

use DateTimeInterface;
use Exception;
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
     * @return array<string, string>
     */
    public function columnTypes(string $table): array
    {
        $table = $this->getTablePrefix().$table;

        if (isset($this->columnTypes[$table])) {
            return $this->columnTypes[$table];
        }

        if (! preg_match('/^\w+$/', $table)) {
            return [];
        }

        try {
            $columns = $this->select('DESC '.$table);
        } catch (QueryException) {
            return [];
        }

        return $this->columnTypes[$table] = array_column($columns, 'Type', 'Field');
    }

    /**
     * Manticore reports a repeated id as a generic error and not as MySQL's 1062. Knowing it is what
     * lets createOrFirst() and firstOrCreate() find the document that already exists.
     *
     * @return bool
     */
    protected function isUniqueConstraintError(Exception $exception)
    {
        return str_contains($exception->getMessage(), 'duplicate id');
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
     */
    protected function getDefaultQueryGrammar(): ManticoreQueryGrammar
    {
        return $this->withManticoreTablePrefix(new ManticoreQueryGrammar($this));
    }

    /**
     * Use the Manticore schema grammar in place of the MySQL one.
     */
    protected function getDefaultSchemaGrammar(): ManticoreSchemaGrammar
    {
        return $this->withManticoreTablePrefix(new ManticoreSchemaGrammar($this));
    }

    /**
     * Laravel 12 dropped Connection::withTablePrefix() — grammars now read the prefix
     * straight from their connection. We only call it on the versions that still have it,
     * which also need to be told the connection, for toRawSql().
     *
     * @template TGrammar of \Illuminate\Database\Grammar
     *
     * @param  TGrammar  $grammar
     * @return TGrammar
     */
    protected function withManticoreTablePrefix($grammar)
    {
        if (method_exists($grammar, 'setConnection')) {
            $grammar->setConnection($this);
        }

        return method_exists($this, 'withTablePrefix')
            ? $this->withTablePrefix($grammar)
            : $grammar;
    }

    /**
     * Hand back a schema builder that introspects Manticore via SHOW TABLES / DESC.
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
     */
    protected function getDefaultPostProcessor(): ManticoreProcessor
    {
        return new ManticoreProcessor;
    }

    /**
     * Start queries on the Manticore-aware builder, so models inherit match()/option()/facet().
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
