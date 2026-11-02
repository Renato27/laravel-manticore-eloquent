<?php

namespace ManticoreEloquent\Eloquent;

use Illuminate\Database\Eloquent\Model;
use ManticoreEloquent\Concerns\HasManticore;

abstract class ManticoreModel extends Model
{
    use HasManticore;

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
