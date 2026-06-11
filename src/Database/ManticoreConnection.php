<?php

namespace ManticoreEloquent\Database;

use Illuminate\Database\MySqlConnection;
use ManticoreEloquent\Database\Query\Grammars\ManticoreQueryGrammar;
use ManticoreEloquent\Database\Query\ManticoreQueryBuilder;
use ManticoreEloquent\Database\Query\Processors\ManticoreProcessor;
use ManticoreEloquent\Database\Schema\ManticoreSchemaBuilder;
use ManticoreEloquent\Database\Schema\ManticoreSchemaGrammar;

class ManticoreConnection extends MySqlConnection
{
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
