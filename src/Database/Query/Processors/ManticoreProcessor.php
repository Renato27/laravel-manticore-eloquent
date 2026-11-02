<?php

namespace ManticoreEloquent\Database\Query\Processors;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Processors\MySqlProcessor;

class ManticoreProcessor extends MySqlProcessor
{
    /**
     * The MySQL protocol reports 0 as the id of a UUID document, which only LAST_INSERT_ID() has.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  string  $sql
     * @param  array  $values
     * @param  string|null  $sequence
     * @return int|string
     */
    public function processInsertGetId(Builder $query, $sql, $values, $sequence = null)
    {
        $id = parent::processInsertGetId($query, $sql, $values, $sequence);

        if ($id !== 0) {
            return $id;
        }

        $row = (array) $query->getConnection()->selectOne('SELECT LAST_INSERT_ID()');

        return reset($row);
    }
}
