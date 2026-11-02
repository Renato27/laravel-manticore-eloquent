<?php

namespace ManticoreEloquent\Concerns;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use ManticoreEloquent\Database\ManticoreConnection;

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

    /**
     * A timestamp column holds a Unix integer, so that is what a date attribute stores while
     * the model is on Manticore. Reading it back needs nothing extra, since Eloquent already
     * takes an integer as a date. Any other connection keeps its own format.
     *
     * @param  mixed  $value
     * @return mixed
     */
    public function fromDateTime($value)
    {
        return $this->getConnection() instanceof ManticoreConnection && ! empty($value)
            ? $this->asDateTime($value)->getTimestamp()
            : parent::fromDateTime($value);
    }
}
