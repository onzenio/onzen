<?php

namespace App\Support;

class VaultRef
{
    /**
     * Mask an opaque vault reference, keeping only the scheme and the last
     * four characters visible. Secrets themselves never live in a ref.
     */
    public static function mask(?string $ref): ?string
    {
        if ($ref === null || $ref === '') {
            return null;
        }

        $separator = strpos($ref, ':');
        $prefix = $separator === false ? '' : substr($ref, 0, $separator + 1);
        $value = $separator === false ? $ref : substr($ref, $separator + 1);

        if ($value === '') {
            return $prefix.'****';
        }

        if (strlen($value) <= 4) {
            return $prefix.str_repeat('*', strlen($value));
        }

        return $prefix.str_repeat('*', max(4, strlen($value) - 4)).substr($value, -4);
    }
}
