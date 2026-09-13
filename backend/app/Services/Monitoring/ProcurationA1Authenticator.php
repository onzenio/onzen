<?php

namespace App\Services\Monitoring;

use App\Exceptions\SerproBlockedException;
use App\Models\Account;
use App\Models\AccountCertificate;
use App\Models\Client;
use App\Models\SerproRequestAuthor;
use Carbon\CarbonInterface;

/**
 * A1 (Certificado Digital) authentication of a procuração.
 *
 * Port of the legacy `ProcurationA1Authenticator` adapted to the OneFisc
 * model: the certificate lives once per Account ({@see AccountCertificate}),
 * not on the Client. Fail-closed by design — only an active, unexpired
 * certificate of the Client's Account authenticates, and a Client/author from
 * another Account is refused before any lookup leak. Every refusal carries a
 * factual reason code, never certificate material.
 */
final class ProcurationA1Authenticator
{
    /**
     * @throws SerproBlockedException when Account/Client/author/certificate do
     *                                not line up into a valid A1 authentication.
     */
    public function authenticate(
        Account $account,
        Client $client,
        ?SerproRequestAuthor $author = null,
        ?CarbonInterface $at = null,
    ): AccountCertificate {
        $at ??= now();

        if ($client->account_id !== $account->id) {
            throw new SerproBlockedException('client_account_mismatch');
        }

        $certificate = $this->activeCertificate($account, $at);

        if ($author !== null) {
            if ($author->account_id !== $account->id) {
                throw new SerproBlockedException('author_account_mismatch');
            }

            if (! $author->isEligible($at)) {
                throw new SerproBlockedException('author_ineligible');
            }

            if ($author->certificate_thumbprint !== null
                && $author->certificate_thumbprint !== $certificate->thumbprint) {
                throw new SerproBlockedException('author_certificate_mismatch');
            }
        }

        return $certificate;
    }

    /**
     * The single active certificate of the Account, or a factual refusal.
     *
     * @throws SerproBlockedException
     */
    public function activeCertificate(Account $account, ?CarbonInterface $at = null): AccountCertificate
    {
        $certificate = AccountCertificate::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $account->id)
            ->first();

        if ($certificate === null) {
            throw new SerproBlockedException('account_certificate_unavailable');
        }

        if ($certificate->isExpired($at)) {
            throw new SerproBlockedException('account_certificate_expired');
        }

        return $certificate;
    }
}
