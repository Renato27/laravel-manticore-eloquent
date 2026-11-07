<?php

namespace ManticoreEloquent\Eloquent\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Expression;
use ManticoreEloquent\Database\Query\VectorArray;

/**
 * Several vectors per document, for a float_vector_array column: [[0.1, 0.2], [0.3, 0.4]].
 */
class VectorArrayCast implements CastsAttributes
{
    /**
     * A float_vector_array reads back as "[[0.100000,0.200000]]", and "[]" when it has no vectors.
     *
     * @param  Model  $model
     * @param  mixed  $value
     * @param  array<string, mixed>  $attributes
     * @return array<int, mixed>|null
     */
    public function get($model, string $key, $value, array $attributes)
    {
        if (is_null($value) || is_array($value)) {
            return $value;
        }

        $value = (string) ($value instanceof Expression ? $value->getValue($model->getConnection()->getQueryGrammar()) : $value);

        return VectorArray::parse($value);
    }

    /**
     * @param  Model  $model
     * @param  mixed  $value
     * @param  array<string, mixed>  $attributes
     * @return mixed
     */
    public function set($model, string $key, $value, array $attributes)
    {
        return is_array($value) ? VectorArray::literal($value, $key) : $value;
    }
}
