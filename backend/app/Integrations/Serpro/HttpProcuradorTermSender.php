<?php

namespace App\Integrations\Serpro;

class HttpProcuradorTermSender implements ProcuradorTermSender
{
    public function __construct(private readonly SerproTransport $transport) {}

    public function send(array $signedTerm): array
    {
        $response = $this->transport->request('autenticar-procurador', [
            'termo' => $signedTerm,
        ], []);

        $token = $response['token'] ?? null;
        $expiresAt = $response['expira_em'] ?? null;

        if (! is_string($token) || ! is_string($expiresAt)) {
            throw new SerproTransportException('Resposta de autenticação do procurador inválida.');
        }

        return ['token' => $token, 'expires_at' => $expiresAt];
    }
}
