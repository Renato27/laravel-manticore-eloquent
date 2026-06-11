<?php

namespace ManticoreEloquent\Database\Query\Processors;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Processors\MySqlProcessor;

class ManticoreProcessor extends MySqlProcessor
{
    /**
     * Insert with id = 0 and Manticore assigns the document id; it comes back through
     * lastInsertId() over the MySQL protocol, so the parent already handles it.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  string  $sql
     * @param  array  $values
     * @param  string|null  $sequence
     * @return int
     */
    public function processInsertGetId(Builder $query, $sql, $values, $sequence = null)
    {
        return parent::processInsertGetId($query, $sql, $values, $sequence);
    }
}
