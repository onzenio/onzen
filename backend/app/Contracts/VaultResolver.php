<?php

namespace App\Contracts;

interface VaultResolver
{
    public function put(string $ref, array|string $value): void;

    public function get(string $ref): array|string|null;

    public function forget(string $ref): void;
}
