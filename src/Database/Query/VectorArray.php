<?php

namespace ManticoreEloquent\Database\Query;

use Illuminate\Database\Query\Expression;
use InvalidArgumentException;

/**
 * A float_vector_array value as Manticore takes it: an array of vectors such as [[0.1, 0.2]],
 * where [] means no vectors. The server refuses it as a quoted string, so it is inlined, which is
 * safe since every component is cast to a float.
 *
 * @see https://manual.manticoresearch.com/Creating_a_table/Data_types#Float-vector-array
 */
final class VectorArray
{
    /**
     * The literal of a list of vectors, or of the form the server reads back, "[[1.000000,0.5]]".
     *
     * @param  array<int, mixed>|string  $value
     *
     * @throws InvalidArgumentException
     */
    public static function literal(array|string $value, string $column): Expression
    {
        $vectors = is_string($value) ? self::parse($value) : $value;

        if ($vectors === null || array_filter($vectors, static fn ($vector) => ! self::isFlatList($vector)) !== []) {
            throw new InvalidArgumentException("Column [{$column}] is of type float_vector_array, so it takes a list of vectors, such as [[0.1, 0.2], [0.3, 0.4]].");
        }

        return new Expression('['.implode(', ', array_map(
            static fn (array $vector) => '['.implode(', ', array_map(static fn ($component) => var_export((float) $component, true), $vector)).']',
            $vectors
        )).']');
    }

    /**
     * Whether a value is an array of scalars, as a vector or a multi-value attribute is. A nested
     * array or object would be cast to 1, so it is not one.
     */
    public static function isFlatList(mixed $value): bool
    {
        return is_array($value) && array_filter($value, static fn ($part) => is_array($part) || is_object($part)) === [];
    }

    /**
     * The list, when it is a flat one.
     *
     * @param  array<int|string, mixed>  $list
     * @return array<int|string, mixed>
     *
     * @throws InvalidArgumentException
     */
    public static function requireFlatList(array $list, string $column): array
    {
        if (! self::isFlatList($list)) {
            throw new InvalidArgumentException("Column [{$column}] takes a list of numbers, such as [0.1, 0.2], not a list inside it.");
        }

        return $list;
    }

    /**
     * The vectors of the form the server reads back, which is JSON, or null when the string is not
     * a JSON array. A JSON object stays an object, so literal() rejects it rather than dropping its
     * keys, and checks that each element is a vector.
     *
     * @return array<int, mixed>|null
     */
    public static function parse(string $value): ?array
    {
        if (trim($value) === '') {
            return [];
        }

        $vectors = json_decode($value);

        return is_array($vectors) ? $vectors : null;
    }
}
