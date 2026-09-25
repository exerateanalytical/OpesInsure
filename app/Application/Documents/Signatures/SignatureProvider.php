<?php

declare(strict_types=1);

namespace App\Application\Documents\Signatures;

use App\Models\User;

/**
 * REQ-DOC-012 provider-agnostic e-signature port. The platform owns the request / signer records and the
 * evidence; a provider only initiates (optionally returning its own reference) and captures one signer's act.
 * Only the MANUAL click-to-sign adapter exists; external vendors plug in behind this interface.
 */
interface SignatureProvider
{
    public function code(): string;

    /** @param list<object> $signers  @return string|null provider reference */
    public function initiate(object $request, array $signers): ?string;

    /**
     * @param  array{ip?: ?string, user_agent?: ?string, consent_accepted: bool}  $context
     * @return array{method: string, evidence: array<string, mixed>}
     */
    public function capture(object $request, object $signer, User $user, array $context): array;
}
