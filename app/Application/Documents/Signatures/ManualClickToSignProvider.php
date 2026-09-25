<?php

declare(strict_types=1);

namespace App\Application\Documents\Signatures;

use App\Application\Documents\DocumentGovernanceProblem;
use App\Models\User;

/** REQ-DOC-012 MANUAL adapter: an authenticated signer ticks the consent statement and clicks "sign". */
final class ManualClickToSignProvider implements SignatureProvider
{
    public function code(): string
    {
        return 'MANUAL';
    }

    public function initiate(object $request, array $signers): ?string
    {
        return null;
    }

    public function capture(object $request, object $signer, User $user, array $context): array
    {
        if (! ($context['consent_accepted'] ?? false)) {
            throw DocumentGovernanceProblem::make('CONSENT_REQUIRED', 422, 'The signer must accept the consent statement.');
        }

        return ['method' => 'CLICK_TO_SIGN', 'evidence' => [
            'authenticated_user_id' => $user->id,
            'consent_text_sha256' => hash('sha256', (string) $request->consent_text),
            'document_sha256' => $request->document_sha256,
            'ip' => $context['ip'] ?? null,
            'user_agent' => isset($context['user_agent']) ? substr((string) $context['user_agent'], 0, 255) : null,
        ]];
    }
}
