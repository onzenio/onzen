<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class Cnpj implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match('/^\d{14}$/', $value)) {
            $fail('O CNPJ informado é inválido.');

            return;
        }

        if (preg_match('/^(\d)\1{13}$/', $value) === 1) {
            $fail('O CNPJ informado é inválido.');

            return;
        }

        $base = substr($value, 0, 12);

        if ((int) $value[12] !== self::digit($base, [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2])
            || (int) $value[13] !== self::digit($base.substr($value, 12, 1), [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2])) {
            $fail('O CNPJ informado é inválido.');
        }
    }

    /**
     * @param  array<int, int>  $weights
     */
    protected static function digit(string $digits, array $weights): int
    {
        $sum = 0;

        foreach ($weights as $i => $weight) {
            $sum += ((int) $digits[$i]) * $weight;
        }

        $rest = $sum % 11;

        return $rest < 2 ? 0 : 11 - $rest;
    }
}
