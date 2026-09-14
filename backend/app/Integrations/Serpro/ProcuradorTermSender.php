<?php

namespace App\Integrations\Serpro;

interface ProcuradorTermSender
{
    /**
     * @param array<string, string> $signedTerm
     * @return array{token: string, expires_at: string}
     */
    public function send(array $signedTerm): array;
}
