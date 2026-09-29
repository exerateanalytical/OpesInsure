<?php

declare(strict_types=1);

namespace App\Application\Integrations\Activa;

use App\Application\Audit\AuditWriter;
use App\Application\Documents\Engine\CarrierDocumentService;
use App\Application\Events\OutboxWriter;
use App\Application\Policies\IssuanceQueue\IssuanceException;
use App\Application\Policies\IssuanceQueue\IssuanceQueueService;
use App\Models\CarrierApiConnection;
use App\Models\CarrierApiSyncRecord;
use App\Models\PaymentIntentRecord;
use App\Models\Policy;
use App\Models\Proposal;
use App\Models\QuoteOffer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Puts our issued policies and collected payments on Activa's books and brings Activa's documents back.
 *
 * Steps per policy (each one a carrier_api_sync_records row, so a failed later step never repeats an earlier one):
 *   CONTRACT     travel: quotes_requests → POST /travel/policies (payment MANAGED_BY_PARTNER);
 *                other lines: SouscriptionCMR/NewContract*CMR, or RenouvellementCMR/Renew*CMR when the policy renews a
 *                policy Activa already holds. Keeps Activa's ids (travel policy_id / idctr, policy number).
 *   ATTESTATION  motor only: SouscriptionCMR/AttestationCMR/{idctr} (consumes an attestation number: sent once).
 *   DOCUMENT     travel: GET /travel/policies/{id}/certificate; others: Documents/contrat → download. Stored through
 *                CarrierDocumentService as the carrier original (it replaces our rendered certificate of that kind).
 * Per payment: PAYMENT  EncaissementCMR/Encaissement once the contract exists (not travel: paid via the partner).
 * Travel servicing: UPDATE (PATCH when our policy version moves) and CANCEL (when our policy is cancelled).
 *
 * Failures: waiting on credentials / Activa's approval → CONFIG_REQUIRED (resumed automatically by the
 * reconciliation once auth works); transient → RETRY_PENDING with exponential backoff; data problems →
 * MAPPING_REQUIRED / FAILED, surfaced in the issuance-exception queue and the operations exception queue.
 */
final class ActivaPolicySync
{
    public const CONTRACT = 'CONTRACT';

    public const ATTESTATION = 'ATTESTATION';

    public const DOCUMENT = 'DOCUMENT';

    public const PAYMENT = 'PAYMENT';

    public const UPDATE = 'UPDATE';

    public const CANCEL = 'CANCEL';

    public function __construct(
        private readonly ActivaConnections $connections,
        private readonly ActivaApi $api,
        private readonly ActivaPayloadMapper $mapper,
        private readonly CarrierDocumentService $documents,
        private readonly IssuanceQueueService $issuanceQueue,
        private readonly AuditWriter $audit,
        private readonly OutboxWriter $outbox,
    ) {}

    /** Whether a policy belongs to an Activa-connected carrier and a line Activa's APIs cover. */
    public function applies(Policy $policy): bool
    {
        return $this->connections->forCarrier($policy->carrier_id) !== null && $this->mapper->family($policy) !== null;
    }

    /** Runs every pending step of a policy. @return array<string, string> step => status */
    public function syncPolicy(Policy $policy, bool $force = false): array
    {
        $c = $this->connections->forCarrier($policy->carrier_id);
        $family = $this->mapper->family($policy);
        if ($c === null || $family === null) {
            return [];
        }
        $out = [];
        $contract = $this->step($c, $policy, 'policy', $policy->id, self::CONTRACT, $force, fn (CarrierApiSyncRecord $r) => $family === 'TRAVEL'
            ? $this->travelSubscribe($c, $policy, $r) : $this->subscribeContract($c, $policy, $family, $r));
        $out[self::CONTRACT] = $contract->status;
        if ($contract->status !== 'SYNCED') {
            return $out;
        }
        if ($family === 'AUTO') {
            $att = $this->step($c, $policy, 'policy', $policy->id, self::ATTESTATION, $force, fn (CarrierApiSyncRecord $r) => $this->attest($c, $policy, $contract, $r));
            $out[self::ATTESTATION] = $att->status;
        }
        $doc = $this->step($c, $policy, 'policy', $policy->id, self::DOCUMENT, $force, fn (CarrierApiSyncRecord $r) => $this->fetchDocument($c, $policy, $family, $contract, $r));
        $out[self::DOCUMENT] = $doc->status;

        if ($family !== 'TRAVEL') {
            foreach ($this->succeededPayments($policy) as $payment) {
                $out[self::PAYMENT.':'.$payment->id] = $this->syncPayment($payment, $force) ?? 'SKIPPED';
            }
        }

        return $out;
    }

    /** Records a collected payment with Activa (EncaissementCMR). Null when not applicable (yet). */
    public function syncPayment(PaymentIntentRecord $payment, bool $force = false): ?string
    {
        if ($payment->status !== 'SUCCEEDED' || $payment->proposal_id === null) {
            return null;
        }
        $policy = Policy::where('proposal_id', $payment->proposal_id)->orderByDesc('issued_at')->first();
        if ($policy === null || ! $this->applies($policy) || $this->mapper->family($policy) === 'TRAVEL') {
            return null;
        }
        $contract = $this->record($policy->carrier_id, 'policy', $policy->id, self::CONTRACT);
        if ($contract?->status !== 'SYNCED') {
            return null; // the contract sync picks the payment up once Activa holds the contract
        }
        $c = $this->connections->forCarrier($policy->carrier_id);

        return $this->step($c, $policy, 'payment_intent', $payment->id, self::PAYMENT, $force, function (CarrierApiSyncRecord $r) use ($c, $payment, $policy, $contract) {
            $row = $this->mapper->payment($payment, $policy, ['idctr' => $contract->external_reference, 'policy_number' => $contract->external_policy_number], $c);
            $res = $this->api->recordPayment($c, [$row], ['subject_type' => 'payment_intent', 'subject_id' => $payment->id]);
            $ref = data_get($res, 'numeenca') ?? data_get($res, '0.numeenca') ?? data_get($res, 'id') ?? data_get($res, 'value');

            return ['external_reference' => is_scalar($ref) && (string) $ref !== '' ? (string) $ref : $payment->id, 'external_data' => ['amount' => (int) $payment->amount_minor]];
        })->status;
    }

    /** Travel: PATCH Activa's policy with our current dates / holder. */
    public function updateTravel(Policy $policy): ?string
    {
        $contract = $this->record($policy->carrier_id, 'policy', $policy->id, self::CONTRACT);
        $c = $this->connections->forCarrier($policy->carrier_id);
        if ($c === null || $contract?->status !== 'SYNCED' || $this->mapper->family($policy) !== 'TRAVEL') {
            return null;
        }

        return $this->step($c, $policy, 'policy', $policy->id, self::UPDATE, true, function (CarrierApiSyncRecord $r) use ($c, $policy, $contract) {
            $res = $this->api->travelUpdatePolicy($c, (string) $contract->external_reference, $this->mapper->travelUpdate($policy), ['subject_type' => 'policy', 'subject_id' => $policy->id]);
            $contract->update(['external_data' => [...($contract->external_data ?? []), 'synced_version' => (int) $policy->version, 'status' => $res['status'] ?? null]]);

            return ['external_reference' => (string) ($res['policy_id'] ?? $contract->external_reference), 'external_policy_number' => $res['policy_number'] ?? $contract->external_policy_number,
                'external_data' => ['status' => $res['status'] ?? null, 'version' => (int) $policy->version]];
        })->status;
    }

    /** Travel: cancel Activa's policy. */
    public function cancelTravel(Policy $policy, string $reason): ?string
    {
        $contract = $this->record($policy->carrier_id, 'policy', $policy->id, self::CONTRACT);
        $c = $this->connections->forCarrier($policy->carrier_id);
        if ($c === null || $contract?->status !== 'SYNCED' || $this->mapper->family($policy) !== 'TRAVEL') {
            return null;
        }

        return $this->step($c, $policy, 'policy', $policy->id, self::CANCEL, false, function (CarrierApiSyncRecord $r) use ($c, $policy, $contract, $reason) {
            $res = $this->api->travelCancelPolicy($c, (string) $contract->external_reference, mb_substr($reason, 0, 250), ['subject_type' => 'policy', 'subject_id' => $policy->id]);

            return ['external_reference' => $contract->external_reference, 'external_data' => ['status' => $res['status'] ?? 'cancelled']];
        })->status;
    }

    public function record(string $carrierId, string $subjectType, string $subjectId, string $operation): ?CarrierApiSyncRecord
    {
        return CarrierApiSyncRecord::where(['carrier_id' => $carrierId, 'subject_type' => $subjectType, 'subject_id' => $subjectId, 'operation' => $operation])->first();
    }

    // ---- steps -----------------------------------------------------------------------------------------------------

    private function travelSubscribe(CarrierApiConnection $c, Policy $policy, CarrierApiSyncRecord $r): array
    {
        $subject = ['subject_type' => 'policy', 'subject_id' => $policy->id];
        $quote = $this->api->travelQuote($c, $this->mapper->travelQuote($policy, $c), $subject, $c->setting('travel.language', config('activa.travel.language')));
        $code = $quote['quote_code'] ?? null;
        if (! is_string($code) || $code === '') {
            throw ActivaException::make(ActivaException::INVALID_RESPONSE, ActivaServices::TRAVEL, 'getTravelQuote', null, 'No quote_code.');
        }
        $created = $this->api->travelCreatePolicy($c, $this->mapper->travelPolicy($policy, $code, $c), $subject);
        $id = $created['policy_id'] ?? null;
        if ($id === null || $id === '') {
            throw ActivaException::make(ActivaException::INVALID_RESPONSE, ActivaServices::TRAVEL, 'createTravelPolicy', null, 'No policy_id.');
        }
        $offer = $policy->proposal?->offer;
        if ($offer instanceof QuoteOffer && $offer->external_reference === null) {
            $offer->forceFill(['external_reference' => $code])->save();
        }

        return ['external_reference' => (string) $id, 'external_policy_number' => $created['policy_number'] ?? null,
            'external_data' => ['quote_code' => $code, 'status' => $created['status'] ?? null, 'family' => 'TRAVEL', 'synced_version' => (int) $policy->version]];
    }

    private function subscribeContract(CarrierApiConnection $c, Policy $policy, string $family, CarrierApiSyncRecord $r): array
    {
        $subject = ['subject_type' => 'policy', 'subject_id' => $policy->id];
        // A retried POST whose first answer never arrived: adopt the contract if Activa already created it.
        if ($r->attempts > 0 && ($existing = $this->findExistingContract($c, $policy, $family)) !== null) {
            return ['external_reference' => $existing['idctr'], 'external_policy_number' => $existing['policy_number'], 'external_data' => ['family' => $family, 'adopted' => true]];
        }
        $previous = $policy->previous_policy_id ? $this->record($policy->carrier_id, 'policy', $policy->previous_policy_id, self::CONTRACT) : null;
        $prev = $previous?->status === 'SYNCED' ? ['idctr' => $previous->external_reference, 'policy_number' => $previous->external_policy_number] : null;
        $body = $this->mapper->contract($policy, $family, $c, $prev);
        $res = $prev ? $this->api->renewContract($c, $family, $body, $subject) : $this->api->newContract($c, $family, $body, $subject);
        $ids = ActivaApi::contractIds($res);
        if ($ids['idctr'] === null) {
            $ids = $this->findExistingContract($c, $policy, $family) ?? $ids;
        }
        if ($ids['idctr'] === null) {
            throw ActivaException::make(ActivaException::INVALID_RESPONSE, ActivaServices::SUBSCRIPTION, $prev ? 'RenewContract' : 'NewContract', null, 'No contract id (idctr) in the answer.');
        }

        return ['external_reference' => $ids['idctr'], 'external_policy_number' => $ids['policy_number'], 'external_data' => ['family' => $family, 'renewal_of' => $prev['idctr'] ?? null]];
    }

    private function attest(CarrierApiConnection $c, Policy $policy, CarrierApiSyncRecord $contract, CarrierApiSyncRecord $r): array
    {
        $res = $this->api->attestation($c, (string) $contract->external_reference, $this->mapper->attestation($policy, $c), null, ['subject_type' => 'policy', 'subject_id' => $policy->id]);
        $num = data_get($res, 'numeattestation') ?? data_get($res, 'numeroAttestation') ?? data_get($res, 'value');

        return ['external_reference' => is_scalar($num) && (string) $num !== '' ? (string) $num : (string) $contract->external_reference, 'external_data' => ['attested_at' => now()->toIso8601String()]];
    }

    private function fetchDocument(CarrierApiConnection $c, Policy $policy, string $family, CarrierApiSyncRecord $contract, CarrierApiSyncRecord $r): array
    {
        $subject = ['subject_type' => 'policy', 'subject_id' => $policy->id];
        $number = $contract->external_policy_number ?: $contract->external_reference;
        if ($family === 'TRAVEL') {
            [$bytes, $mime] = $this->api->travelCertificate($c, (string) $contract->external_reference, $subject);
            $type = (string) config('activa.document_types.TRAVEL');
            $name = 'certificate';
        } else {
            $listing = $this->api->contractDocuments($c, (string) $number, $subject);
            $docs = array_values(array_filter((array) ($listing['documents'] ?? []), fn ($d) => is_array($d) && ! empty($d['nomDocument'])));
            if ($docs === []) {
                // Activa produces its documents after the contract; try again later.
                throw ActivaException::make(ActivaException::UNAVAILABLE, ActivaServices::SUBSCRIPTION, 'Documents.contrat', null, 'No document available yet.');
            }
            $wanted = $family === 'AUTO' ? 'ATTEST' : 'CONTRAT';
            usort($docs, fn ($a, $b) => (int) ! str_contains(strtoupper(($a['typeDocument'] ?? '').' '.$a['nomDocument']), $wanted) <=> (int) ! str_contains(strtoupper(($b['typeDocument'] ?? '').' '.$b['nomDocument']), $wanted));
            $name = (string) $docs[0]['nomDocument'];
            [$bytes, $mime] = $this->api->downloadContractDocument($c, (string) $number, $name, $subject);
            $type = (string) config('activa.document_types.'.(str_contains(strtoupper(($docs[0]['typeDocument'] ?? '').' '.$name), 'ATTEST') || $family === 'AUTO' ? $family : 'CONTRACT'));
        }

        $existing = \App\Models\Document::where('policy_id', $policy->id)->where('sha256', hash('sha256', $bytes))->where('is_carrier_original', true)->first();
        $doc = $existing ?? $this->documents->upload($policy, $bytes, $mime, [
            'document_type_code' => $type, 'issue_date' => now()->toDateString(), 'carrier_document_number' => (string) $number, 'language' => 'FR', 'source' => 'CARRIER_API',
        ], null);
        $this->outbox->record('integration.carrier_api.document_received', 'policy', $policy->id, ['policy_id' => $policy->id, 'document_id' => $doc->id, 'carrier_id' => $policy->carrier_id]);

        return ['external_reference' => (string) $number, 'document_id' => $doc->id, 'external_data' => ['document_name' => $name, 'document_type_code' => $type]];
    }

    /** @return array{idctr: string, policy_number: ?string}|null */
    private function findExistingContract(CarrierApiConnection $c, Policy $policy, string $family): ?array
    {
        $facts = (array) ($this->mapper->quote($policy)?->risk_facts ?? []);
        $plate = $family === 'AUTO' ? ($facts['registration_number'] ?? data_get($facts, 'vehicle.registration_number') ?? data_get($facts, 'vehicles.0.registration_number')) : null;
        $query = $plate ? ['numimma' => $plate] : ['nomclient' => $this->mapper->person($policy->party)['last_name']];
        if (array_filter($query) === []) {
            return null;
        }
        try {
            $res = $this->api->searchContracts($c, $query);
        } catch (ActivaException) {
            return null;
        }
        $rows = array_is_list($res) ? $res : (array) ($res['contrats'] ?? $res['data'] ?? $res['items'] ?? []);
        foreach ($rows as $row) {
            if (is_array($row) && ($row['refeinte'] ?? null) === $policy->policy_number) {
                $ids = ActivaApi::contractIds($row);

                return $ids['idctr'] !== null ? $ids : null;
            }
        }

        return null;
    }

    /** @return list<PaymentIntentRecord> */
    private function succeededPayments(Policy $policy): array
    {
        return PaymentIntentRecord::where('proposal_id', $policy->proposal_id)->where('status', 'SUCCEEDED')->orderBy('created_at')->get()->all();
    }

    // ---- step runner ---------------------------------------------------------------------------------------------

    /** @param callable(CarrierApiSyncRecord): array $run returns the columns to store on success */
    private function step(CarrierApiConnection $c, Policy $policy, string $subjectType, string $subjectId, string $operation, bool $force, callable $run): CarrierApiSyncRecord
    {
        $r = CarrierApiSyncRecord::firstOrCreate(
            ['carrier_id' => $policy->carrier_id, 'subject_type' => $subjectType, 'subject_id' => $subjectId, 'operation' => $operation],
            ['connection_id' => $c->id, 'tenant_id' => $policy->tenant_id, 'policy_id' => $policy->id, 'status' => 'PENDING', 'attempts' => 0, 'external_data' => []],
        );
        if ($r->status === 'SYNCED' && ! ($force && in_array($operation, [self::UPDATE], true))) {
            return $r;
        }
        if (! $force && $r->status === 'RETRY_PENDING' && $r->next_attempt_at !== null && $r->next_attempt_at->isFuture()) {
            return $r;
        }
        // One worker per step: a concurrent run (webhook replay, reconciliation) leaves it alone.
        $lock = cache()->lock("activa:sync:{$r->id}", 300);
        if (! $lock->get()) {
            return $r;
        }
        try {
            $r->refresh();
            if ($r->status === 'SYNCED' && $operation !== self::UPDATE) {
                return $r;
            }
            $r->forceFill(['connection_id' => $c->id, 'last_attempt_at' => now()])->save();
            try {
                $result = $run($r);
            } catch (ActivaException $e) {
                return $this->failed($r, $policy, $e);
            } catch (ValidationException $e) {
                return $this->failed($r, $policy, ActivaException::make(ActivaException::REJECTED, null, $operation, null, 'Document store refused: '.$e->validator->errors()->first()));
            } catch (Throwable $e) {
                report($e);

                return $this->failed($r, $policy, ActivaException::make(ActivaException::INVALID_RESPONSE, null, $operation, null, 'Unexpected error ('.class_basename($e).').'));
            }

            $r->forceFill([
                'status' => 'SYNCED', 'attempts' => $r->attempts + 1, 'synced_at' => now(), 'next_attempt_at' => null, 'last_error_code' => null, 'last_error' => null,
                'external_reference' => $result['external_reference'] ?? $r->external_reference, 'external_policy_number' => $result['external_policy_number'] ?? $r->external_policy_number,
                'document_id' => $result['document_id'] ?? $r->document_id, 'external_data' => [...($r->external_data ?? []), ...($result['external_data'] ?? [])],
            ])->save();
            if ($operation === self::CONTRACT) {
                DB::table('policies')->where('id', $policy->id)->update(['carrier_contract_reference' => mb_substr((string) ($r->external_policy_number ?: $r->external_reference), 0, 128)]);
                $this->closeException($policy);
            }
            $meta = ['carrier_id' => $policy->carrier_id, 'operation' => $operation, 'subject_type' => $subjectType, 'subject_id' => $subjectId, 'external_reference' => $r->external_reference];
            $this->audit->record('integration.carrier_api.synced', 'policy', $policy->id, $meta);
            $this->outbox->record('integration.carrier_api.synced', 'carrier_api_sync_record', $r->id, $meta);

            return $r->refresh();
        } finally {
            $lock->release();
        }
    }

    private function failed(CarrierApiSyncRecord $r, Policy $policy, ActivaException $e): CarrierApiSyncRecord
    {
        $attempts = $r->attempts + 1;
        $max = (int) config('activa.sync.max_attempts', 8);
        [$status, $next] = match (true) {
            $e->waitsOnConfig() => ['CONFIG_REQUIRED', null],
            $e->errorCode === ActivaException::MAPPING_REQUIRED => ['MAPPING_REQUIRED', null],
            ($e->isTransient() || $e->errorCode === ActivaException::INVALID_RESPONSE) && $attempts < $max => ['RETRY_PENDING',
                now()->addSeconds((int) min(86_400, (int) config('activa.sync.base_backoff_seconds', 120) * (2 ** ($attempts - 1))))],
            default => ['FAILED', null],
        };
        $r->forceFill(['status' => $status, 'attempts' => $e->waitsOnConfig() ? $r->attempts : $attempts, 'next_attempt_at' => $next,
            'last_error_code' => $e->errorCode, 'last_error' => mb_substr($e->getMessage(), 0, 2000)])->save();
        $this->audit->record('integration.carrier_api.sync_failed', 'policy', $policy->id,
            ['carrier_id' => $policy->carrier_id, 'operation' => $r->operation, 'subject_type' => $r->subject_type, 'status' => $status, 'attempts' => $r->attempts], $e->errorCode);
        if (in_array($status, ['FAILED', 'MAPPING_REQUIRED'], true)) {
            $this->openException($policy, $r, $e);
        }

        return $r->refresh();
    }

    /** Surfaces a stuck Activa sync in the paid-not-issued / failed-issuance queue (REQ-POL-004). */
    private function openException(Policy $policy, CarrierApiSyncRecord $r, ActivaException $e): void
    {
        try {
            $proposal = Proposal::with('offer.product')->find($policy->proposal_id);
            $payment = $policy->payment_intent_id ? PaymentIntentRecord::find($policy->payment_intent_id)
                : PaymentIntentRecord::where('proposal_id', $policy->proposal_id)->where('status', 'SUCCEEDED')->first();
            if ($proposal && $payment) {
                $this->issuanceQueue->record($payment, $proposal, 'ISSUANCE_REQUEST_FAILED', 'CARRIER_API_'.$e->errorCode, [['code' => 'CARRIER_API_'.$r->operation, 'message' => $e->getMessage()]],
                    $e->getMessage(), null, null, $policy->issuance_request_id);
            }
        } catch (Throwable $inner) {
            report($inner);
        }
    }

    private function closeException(Policy $policy): void
    {
        if ($policy->proposal_id && IssuanceException::where('proposal_id', $policy->proposal_id)->where('status', '<>', 'RESOLVED')->where('reason_code', 'like', 'CARRIER_API_%')->exists()) {
            $this->issuanceQueue->closeFor($policy->proposal_id, 'POLICY_ISSUED', $policy->issuance_request_id, null);
        }
    }
}
