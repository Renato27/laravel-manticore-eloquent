<?php

namespace ManticoreEloquent\Eloquent\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Expression;

class MvaCast implements CastsAttributes
{
    /**
     * A multi-value attribute reads back as "1,2".
     *
     * @param  Model  $model
     * @param  mixed  $value
     * @param  array<string, mixed>  $attributes
     * @return array<int, int>
     */
    public function get($model, string $key, $value, array $attributes)
    {
        if ($value instanceof Expression) {
            $value = trim((string) $value->getValue($model->getConnection()->getQueryGrammar()), '()');
        }

        return in_array($value, [null, ''], true)
            ? []
            : array_map('intval', explode(',', (string) $value));
    }

    /**
     * @param  Model  $model
     * @param  mixed  $value
     * @param  array<string, mixed>  $attributes
     * @return mixed
     */
    public function set($model, string $key, $value, array $attributes)
    {
        return is_array($value)
            ? new Expression('('.implode(', ', array_map('intval', $value)).')')
            : $value;
    }
}
