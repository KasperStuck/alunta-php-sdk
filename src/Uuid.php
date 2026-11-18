<?php

namespace KasperStuck\Alunta\Sdk;

/**
 * Alunta identifiers are UUIDs in the 8-4-4-4-12 hexadecimal form, of any version.
 */
final class Uuid
{
    public static function isValid(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[\da-fA-F]{8}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{12}$/D', $value) === 1;
    }
}
