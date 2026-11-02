<?php

namespace ManticoreEloquent\Database\Query\Processors;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Processors\MySqlProcessor;

class ManticoreProcessor extends MySqlProcessor
{
    /**
     * A join names a column table.column where MySQL names it column, and a star also brings the
     * columns of the other table. On Manticore the left table has no prefix, so it wins, as it
     * does not on MySQL only because the columns come in the other order.
     *
     * @param  array  $results
     * @return array
     */
    public function processSelect(Builder $query, $results)
    {
        if (empty($query->joins)) {
            return $results;
        }

        $leftOnly = in_array($query->from.'.*', (array) $query->columns, true);

        foreach ($results as $index => $row) {
            $columns = [];

            foreach ($row as $name => $value) {
                $isQualified = str_contains($name, '.');
                $isWanted = ! $leftOnly || in_array($name, (array) $query->columns, true);

                if (! $isQualified || $isWanted) {
                    $columns[$isQualified ? substr($name, strpos($name, '.') + 1) : $name] ??= $value;
                }
            }

            $results[$index] = (object) $columns;
        }

        return $results;
    }

    /**
     * The MySQL protocol reports 0 as the id of a UUID document, which only LAST_INSERT_ID() has.
     *
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
