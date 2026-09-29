<?php

declare(strict_types=1);

namespace App\Application\Integrations\Activa;

use App\Application\Distribution\Execution\ExecutionContext;
use App\Application\Distribution\Execution\ExecutionOutcome;
use App\Application\Distribution\Execution\RemoteCarrierConnector;
use App\Models\CarrierApiConnection;
use App\Models\Policy;
use App\Models\Quote;

/**
 * REQ-AOM-002 REMOTE_API for Activa Assurances Cameroun.
 *   QUOTATION        travel: POST /travel/quotes_requests; motor: Tarifiktor tarifpolice.   (subject: quote)
 *   POLICY_ISSUANCE  ActivaPolicySync (travel subscription / SouscriptionCMR contract + documents). (subject: policy)
 * UNDERWRITING and claims have no Activa API: those capabilities stay on their stubs (INTEGRATION_UNAVAILABLE).
 * Not configured / waiting on Activa → INTEGRATION_UNAVAILABLE with error CONFIG_REQUIRED (or the auth verdict),
 * so the capability profile's fallback mode applies until the credentials work.
 */
final class ActivaRemoteConnector implements RemoteCarrierConnector
{
    public const CAPABILITIES = ['QUOTATION', 'POLICY_ISSUANCE'];

    public function __construct(
        private readonly ActivaConnections $connections,
        private readonly ActivaApi $api,
        private readonly ActivaPayloadMapper $mapper,
        private readonly ActivaPolicySync $sync,
    ) {}

    public function handles(string $carrierId): bool
    {
        return $this->connections->forCarrier($carrierId) !== null;
    }

    public function supports(string $capability): bool
    {
        return in_array($capability, self::CAPABILITIES, true);
    }

    public function execute(string $capability, ExecutionContext $context): ExecutionOutcome
    {
        $c = $this->connections->forCarrier($context->carrierId);
        if ($c === null || in_array($c->status, ['CONFIG_REQUIRED', 'DISABLED'], true)) {
            return $this->unavailable($capability, ActivaException::CONFIG_REQUIRED, 'Enter the Activa API credentials (Integrations → Activa Assurances).');
        }
        if ($context->subjectType === 'preview' || $context->subjectId === null) {
            return $c->status === 'ACTIVE'
                ? new ExecutionOutcome(ExecutionOutcome::CARRIER_API_READY, $capability, 'REMOTE_API', self::class, 'activa', 'Activa API connected.')
                : $this->unavailable($capability, (string) ($c->last_error_code ?: $c->status), 'Activa has not accepted the credentials yet; the fallback mode applies.');
        }

        try {
            return match ($capability) {
                'QUOTATION' => $this->quote($c, $context),
                'POLICY_ISSUANCE' => $this->issue($context),
            };
        } catch (ActivaException $e) {
            return $this->unavailable($capability, $e->errorCode, $e->getMessage());
        }
    }

    private function quote(CarrierApiConnection $c, ExecutionContext $context): ExecutionOutcome
    {
        $quote = Quote::find($context->payload['quote_id'] ?? $context->subjectId);
        if ($quote === null) {
            throw ActivaException::make(ActivaException::NOT_FOUND, null, 'quote');
        }
        $subject = ['subject_type' => 'quote', 'subject_id' => $quote->id];
        $family = strtoupper((string) data_get($quote->risk_facts, 'activa.family', '')) ?: config('activa.lines.'.strtoupper((string) $quote->line_code));
        if ($family === 'TRAVEL') {
            $res = $this->api->travelQuote($c, $this->mapper->travelQuote($quote, $c), $subject, $c->setting('travel.language', config('activa.travel.language')));

            return new ExecutionOutcome(ExecutionOutcome::CARRIER_EXECUTED, 'QUOTATION', 'REMOTE_API', self::class, 'activa.travel', null, null, null,
                ['quote_code' => $res['quote_code'] ?? null, 'products' => $res['products'] ?? []]);
        }
        if ($family === 'AUTO') {
            $res = $this->api->price($c, 'tarifpolice', $this->mapper->pricing($quote, $c), $subject);

            return new ExecutionOutcome(ExecutionOutcome::CARRIER_EXECUTED, 'QUOTATION', 'REMOTE_API', self::class, 'activa.tarifiktor', null, null, null, ['pricing' => $res]);
        }

        throw ActivaException::make(ActivaException::MAPPING_REQUIRED, null, 'quote', null, "Activa exposes no quotation API for line {$quote->line_code}.");
    }

    private function issue(ExecutionContext $context): ExecutionOutcome
    {
        $policy = Policy::find($context->payload['policy_id'] ?? $context->subjectId);
        if ($policy === null) {
            throw ActivaException::make(ActivaException::NOT_FOUND, null, 'policy', null, 'Issuance through Activa runs on an issued OpesInsure policy.');
        }
        $steps = $this->sync->syncPolicy($policy, true);
        $contract = $this->sync->record($policy->carrier_id, 'policy', $policy->id, ActivaPolicySync::CONTRACT);
        if ($contract?->status !== 'SYNCED') {
            throw new ActivaException((string) ($contract?->last_error_code ?: ActivaException::UNAVAILABLE), (string) ($contract?->last_error ?: 'Activa contract not created.'));
        }
        $doc = $this->sync->record($policy->carrier_id, 'policy', $policy->id, ActivaPolicySync::DOCUMENT);

        return new ExecutionOutcome(ExecutionOutcome::CARRIER_EXECUTED, 'POLICY_ISSUANCE', 'REMOTE_API', self::class, 'activa.contract', null, null, null,
            ['steps' => $steps, 'contract_reference' => $contract->external_reference, 'policy_number' => $contract->external_policy_number, 'document_id' => $doc?->document_id]);
    }

    private function unavailable(string $capability, string $code, string $message): ExecutionOutcome
    {
        return new ExecutionOutcome(ExecutionOutcome::INTEGRATION_UNAVAILABLE, $capability, 'REMOTE_API', self::class, null, $message, $code);
    }
}
