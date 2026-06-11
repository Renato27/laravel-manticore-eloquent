<?php

namespace ManticoreEloquent\Concerns;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;

trait HasManticore
{
    /**
     * Query this model through Manticore on demand, leaving its normal connection alone.
     * Honors a searchableAs() method to pick the index name.
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public static function manticore(): EloquentBuilder
    {
        $connection = (string) config('manticore-eloquent.connection', 'manticore');

        $instance = new static;
        $instance->setConnection($connection);

        if (method_exists($instance, 'searchableAs')) {
            $index = $instance->searchableAs();
            $instance->setTable(is_array($index) ? implode(',', $index) : (string) $index);
        }

        return $instance->newQuery();
    }
}
