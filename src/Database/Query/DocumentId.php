<?php

namespace ManticoreEloquent\Database\Query;

use InvalidArgumentException;

final class DocumentId
{
    /**
     * The UUIDs Manticore takes as a document id: any case, versions 1 to 8, variants
     * 8/9/a/b. The server refuses everything else, the nil UUID included.
     */
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD';

    /**
     * Check an id and return it in the form to send: a UUID string, or an integer. Numeric
     * strings become integers because Buddy only handles an unquoted numeric id.
     *
     *
     * @throws InvalidArgumentException
     */
    public static function normalize(int|string $id): int|string
    {
        if (is_string($id) && preg_match(self::UUID, $id)) {
            return $id;
        }

        $integer = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($integer === false) {
            throw new InvalidArgumentException("Manticore document ids are positive integers or UUIDs, got [{$id}].");
        }

        return $integer;
    }
}
