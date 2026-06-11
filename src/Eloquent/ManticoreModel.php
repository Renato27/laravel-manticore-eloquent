<?php

namespace ManticoreEloquent\Eloquent;

use Illuminate\Database\Eloquent\Model;

abstract class ManticoreModel extends Model
{
    /**
     * Manticore assigns document ids, but they behave like ordinary incrementing keys.
     *
     * @var bool
     */
    public $incrementing = true;

    /**
     * Document ids are integers.
     *
     * @var string
     */
    protected $keyType = 'int';

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
