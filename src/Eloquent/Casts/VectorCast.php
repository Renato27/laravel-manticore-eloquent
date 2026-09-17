<?php

namespace ManticoreEloquent\Eloquent\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Query\Expression;

class VectorCast implements CastsAttributes
{
    /**
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $key
     * @param  mixed  $value
     * @param  array<string, mixed>  $attributes
     * @return array<int, float>|null
     */
    public function get($model, string $key, $value, array $attributes)
    {
        if (is_null($value) || is_array($value)) {
            return $value;
        }

        $value = trim((string) ($value instanceof Expression ? $value->getValue($model->getConnection()->getQueryGrammar()) : $value), "() \t\n\r");

        return $value === '' ? [] : array_map('floatval', preg_split('/[,\s]+/', $value));
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $key
     * @param  mixed  $value
     * @param  array<string, mixed>  $attributes
     * @return mixed
     */
    public function set($model, string $key, $value, array $attributes)
    {
        if (! is_array($value)) {
            return $value;
        }

        return new Expression('('.implode(', ', array_map(
            static fn ($component) => var_export((float) $component, true),
            $value
        )).')');
    }
}
