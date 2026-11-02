<?php

namespace ManticoreEloquent\Eloquent;

use Illuminate\Database\Eloquent\Model;

abstract class ManticoreModel extends Model
{
    /**
     * Manticore assigns document ids, but they behave like ordinary incrementing keys.
     * A model on a UUID table sets this to false, or uses Laravel's HasUuids trait.
     *
     * @var bool
     */
    public $incrementing = true;

    /**
     * Document ids are integers by default. A model on a UUID table sets this to 'string'.
     *
     * @var string
     */
    protected $keyType = 'int';

    /**
     * Manticore rejects `table`.`column`, so whereKey() and with it find() and findMany() need
     * the bare key name.
     *
     * @return string
     */
    public function getQualifiedKeyName()
    {
        return $this->getKeyName();
    }

    /**
     * Falls back to the configured Manticore connection when the model doesn't set one.
     *
     * @return string|null
     */
    public function getConnectionName()
    {
        return $this->connection
            ?? config('manticore-eloquent.connection', 'manticore');
    }
}
