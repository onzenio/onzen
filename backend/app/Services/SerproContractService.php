<?php

namespace App\Services;

use App\Models\SerproContract;
use App\Models\User;

class SerproContractService
{
    public function __construct(private readonly AuditService $audit) {}

    /**
     * Gate efetivo é singleton: uma única linha dita ambiente e transporte.
     */
    public function getOrCreate(string $environment = SerproContract::ENV_HOMOLOGACAO): SerproContract
    {
        return SerproContract::query()->firstOrCreate(
            [],
            ['environment' => $environment, 'transport_approved' => false],
        );
    }

    public function rotateCredentials(
        SerproContract $contract,
        string $keyRef,
        string $secretRef,
        ?User $actor = null,
    ): SerproContract {
        $contract->forceFill([
            'consumer_key_ref' => $keyRef,
            'consumer_secret_ref' => $secretRef,
        ])->save();

        $this->audit->record(
            $actor,
            $actor?->account_id,
            null,
            'serpro_contract.credentials_rotated',
            [
                'environment' => $contract->environment,
                'consumer_key_masked' => SerproContract::maskRef($keyRef),
                'consumer_secret_masked' => SerproContract::maskRef($secretRef),
            ],
        );

        return $contract->refresh();
    }
}
