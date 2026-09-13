<?php

namespace App\Support;

class CurrentAccount
{
    protected static ?int $accountId = null;

    public static function set(?int $id): void
    {
        static::$accountId = $id;
    }

    public static function get(): ?int
    {
        return static::$accountId;
    }

    public static function clear(): void
    {
        static::$accountId = null;
    }
}
