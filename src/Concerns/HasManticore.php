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

    /**
     * Manticore rejects `table`.`column`, so whereKey() and with it find() need the bare key
     * name there. The model's normal connection keeps the qualified one.
     *
     * @return string
     */
    public function getQualifiedKeyName()
    {
        return $this->isOnManticore() ? $this->getKeyName() : parent::getQualifiedKeyName();
    }

    /**
     * A timestamp column holds a Unix integer, so that is what a date attribute stores while
     * the model is on Manticore. The model's normal connection keeps its own format.
     *
     * @param  mixed  $value
     * @return mixed
     */
    public function fromDateTime($value)
    {
        return $this->isOnManticore() && ! empty($value)
            ? $this->asDateTime($value)->getTimestamp()
            : parent::fromDateTime($value);
    }

    /**
     * Whether this instance is using the Manticore connection.
     *
     * @return bool
     */
    protected function isOnManticore(): bool
    {
        return $this->getConnectionName() === config('manticore-eloquent.connection', 'manticore');
    }
}
