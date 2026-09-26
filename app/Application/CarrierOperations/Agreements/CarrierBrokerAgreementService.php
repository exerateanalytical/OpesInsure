<?php

declare(strict_types=1);

namespace App\Application\CarrierOperations\Agreements;

use App\Application\Audit\AuditWriter;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * REQ-SEED-004 — distribution side of the split agreement model (REQ-DUP-023):
 * carrier_broker_agreements + per-product permissions (can_quote / can_bind / can_collect_premium /
 * requires_carrier_approval) + per-agreement commission. There is no global commission: when neither the
 * product line nor a linked commission_rule_version gives a rate, the rate is null.
 * Monetary authority lives in authority_limits and is owned by the Authority engine (batch 7A).
 */
final class CarrierBrokerAgreementService
{
    public const ACTIONS = ['quote' => 'can_quote', 'bind' => 'can_bind', 'collect_premium' => 'can_collect_premium', 'issue_documents' => 'can_issue_documents', 'service_policy' => 'can_service_policies', 'assist_claims' => 'can_assist_claims', 'endorse' => 'can_endorse', 'renew' => 'can_renew'];

    private const TRANSITIONS = ['DRAFT' => ['ACTIVE', 'TERMINATED'], 'ACTIVE' => ['SUSPENDED', 'TERMINATED'], 'SUSPENDED' => ['ACTIVE', 'TERMINATED']];

    public function __construct(private readonly AuditWriter $audit) {}

    public function create(User $maker, array $data): object
    {
        $id = (string) Str::uuid();
        DB::table('carrier_broker_agreements')->insert([
            'id' => $id, 'carrier_id' => $data['carrier_id'], 'partner_id' => $data['partner_id'], 'agreement_number' => $data['agreement_number'],
            'effective_from' => $data['effective_from'], 'effective_until' => $data['effective_until'] ?? null, 'status' => 'DRAFT',
            'territories' => json_encode(array_values($data['territories'] ?? [])), 'channels' => json_encode(array_values($data['channels'] ?? [])),
            'data_origin' => $data['data_origin'] ?? 'PLATFORM_NORMALIZED', 'is_demo' => (bool) ($data['is_demo'] ?? false),
            'settlement_terms' => isset($data['settlement_terms']) ? json_encode($data['settlement_terms']) : null, 'source_document' => $data['source_document'] ?? null,
            // Gap closure 01 contract terms (never invented: absent terms stay NULL, data_status stays PENDING_PRIVATE_SOURCE).
            ...$this->contractTerms($data),
            'created_by' => $maker->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->audit->record('carrier_broker_agreement.created', 'carrier_broker_agreement', $id, ['agreement_number' => $data['agreement_number']]);

        return $this->find($id);
    }

    /** Upsert one product permission line (line_code, optional insurance_product_id). */
    public function setProduct(User $actor, string $agreementId, array $line): object
    {
        $agreement = $this->find($agreementId);
        if (in_array($agreement->status, ['TERMINATED', 'EXPIRED'], true)) {
            throw ValidationException::withMessages(['status' => 'A terminated agreement cannot be changed.']);
        }
        if (! empty($line['can_bind']) && array_key_exists('can_quote', $line) && ! $line['can_quote']) {
            throw ValidationException::withMessages(['can_bind' => 'Binding requires quoting permission.']);
        }
        if (! empty($line['insurance_product_id'])) {
            $product = DB::table('insurance_products')->where('id', $line['insurance_product_id'])->first(['carrier_id', 'line_code']);
            if (! $product || $product->carrier_id !== $agreement->carrier_id || $product->line_code !== $line['line_code']) {
                throw ValidationException::withMessages(['insurance_product_id' => 'Product must belong to the agreement carrier and line.']);
            }
        }
        if (! empty($line['commission_rule_version_id'])) {
            $rule = DB::table('commission_rule_versions')->where('id', $line['commission_rule_version_id'])->first(['carrier_id', 'partner_id']);
            if (! $rule || $rule->carrier_id !== $agreement->carrier_id || ($rule->partner_id !== null && $rule->partner_id !== $agreement->partner_id)) {
                throw ValidationException::withMessages(['commission_rule_version_id' => 'Commission rule must belong to this carrier (and partner).']);
            }
        }
        $key = ['agreement_id' => $agreementId, 'line_code' => $line['line_code']];
        $q = DB::table('carrier_broker_agreement_products')->where($key)
            ->when($line['insurance_product_id'] ?? null, fn ($q, $p) => $q->where('insurance_product_id', $p), fn ($q) => $q->whereNull('insurance_product_id'));
        $values = array_intersect_key($line, array_flip(['can_quote', 'can_bind', 'can_collect_premium', 'can_issue_documents', 'can_service_policies', 'can_assist_claims', 'can_endorse', 'can_renew', 'requires_carrier_approval', 'commission_rule_version_id', 'commission_basis_points', 'status']));
        $existing = $q->first();
        if ($existing) {
            DB::table('carrier_broker_agreement_products')->where('id', $existing->id)->update([...$values, 'updated_at' => now()]);
            $id = $existing->id;
        } else {
            $id = (string) Str::uuid();
            DB::table('carrier_broker_agreement_products')->insert([...$key, ...$values, 'id' => $id, 'insurance_product_id' => $line['insurance_product_id'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->audit->recordChange('carrier_broker_agreement.product_set', 'carrier_broker_agreement', $agreementId, $existing ? (array) $existing : [], $values, (string) ($line['reason'] ?? 'Agreement product permission updated'), ['line_id' => $id]);

        return DB::table('carrier_broker_agreement_products')->find($id);
    }

    /** Maker-checker: the author of the agreement cannot activate it. */
    public function transition(User $actor, string $agreementId, string $to, string $reason): object
    {
        return DB::transaction(function () use ($actor, $agreementId, $to, $reason): object {
            $a = DB::table('carrier_broker_agreements')->where('id', $agreementId)->lockForUpdate()->first() ?? throw ValidationException::withMessages(['agreement' => 'Unknown agreement.']);
            if (! in_array($to, self::TRANSITIONS[$a->status] ?? [], true)) {
                throw ValidationException::withMessages(['status' => "Cannot move an agreement from {$a->status} to {$to}."]);
            }
            $update = ['status' => $to, 'updated_at' => now()];
            if ($to === 'ACTIVE') {
                if ($a->created_by !== null && $a->created_by === $actor->id) {
                    throw ValidationException::withMessages(['actor' => 'Maker-checker: the author cannot activate their own agreement.']);
                }
                if (! DB::table('carrier_broker_agreement_products')->where('agreement_id', $agreementId)->where('status', 'ACTIVE')->exists()) {
                    throw ValidationException::withMessages(['products' => 'An agreement needs at least one authorised product line before activation.']);
                }
                app(\App\Application\MarketData\MarketDataGates::class)->assertAgreementActivatable($a);
                $update += ['approved_by' => $actor->id, 'approved_at' => now()];
            }
            DB::table('carrier_broker_agreements')->where('id', $agreementId)->update($update);
            $this->audit->recordChange('carrier_broker_agreement.status_changed', 'carrier_broker_agreement', $agreementId, ['status' => $a->status], ['status' => $to], $reason);

            return $this->find($agreementId);
        });
    }

    /**
     * Distribution permission for partner × carrier × line/product × action on a date. The most specific line
     * (product-level before line-level) decides. Returns the commission that applies to this agreement line.
     *
     * @return array{allowed:bool, reason:string, agreement_id:?string, requires_carrier_approval:bool, commission_basis_points:?int}
     */
    public function permits(string $partnerId, string $carrierId, string $lineCode, ?string $productId, string $action, ?string $on = null): array
    {
        $column = self::ACTIONS[$action] ?? throw ValidationException::withMessages(['action' => 'Unknown action.']);
        $on ??= now()->toDateString();
        $deny = fn (string $reason, ?string $agreementId = null) => ['allowed' => false, 'reason' => $reason, 'agreement_id' => $agreementId, 'requires_carrier_approval' => true, 'commission_basis_points' => null];

        $agreements = DB::table('carrier_broker_agreements')->where(['partner_id' => $partnerId, 'carrier_id' => $carrierId])->orderByDesc('effective_from')->get();
        if ($agreements->isEmpty()) {
            return $deny('NO_AGREEMENT');
        }
        $active = $agreements->first(fn ($a) => $a->status === 'ACTIVE' && $a->effective_from <= $on && ($a->effective_until === null || $a->effective_until >= $on));
        if (! $active) {
            return $deny($agreements->contains('status', 'ACTIVE') ? 'OUTSIDE_EFFECTIVE_PERIOD' : 'AGREEMENT_INACTIVE', $agreements->first()->id);
        }
        $line = DB::table('carrier_broker_agreement_products')->where(['agreement_id' => $active->id, 'line_code' => $lineCode, 'status' => 'ACTIVE'])
            ->where(fn ($q) => $q->whereNull('insurance_product_id')->when($productId, fn ($q) => $q->orWhere('insurance_product_id', $productId)))
            ->orderByRaw('insurance_product_id IS NULL')->first();
        if (! $line) {
            return $deny('PRODUCT_NOT_AUTHORISED', $active->id);
        }
        if (! $line->{$column}) {
            return $deny(strtoupper($action).'_NOT_PERMITTED', $active->id);
        }

        return ['allowed' => true, 'reason' => 'WITHIN_AGREEMENT', 'agreement_id' => $active->id,
            'requires_carrier_approval' => (bool) $line->requires_carrier_approval, 'commission_basis_points' => $this->commission($line)];
    }

    /** @return array<string, mixed> */
    private function contractTerms(array $data): array
    {
        $json = fn (string $k) => isset($data[$k]) ? json_encode($data[$k]) : null;

        return array_filter([
            'agreement_type' => $data['agreement_type'] ?? null, 'authorized_cima_branches' => json_encode(array_values($data['authorized_cima_branches'] ?? [])),
            'premium_remittance_terms' => $json('premium_remittance_terms'), 'cancellation_terms' => $json('cancellation_terms'),
            'commission_rule_set_id' => $data['commission_rule_set_id'] ?? null, 'sla_profile_id' => $data['sla_profile_id'] ?? null,
            'settlement_profile_id' => $data['settlement_profile_id'] ?? null, 'data_exchange_mode' => $data['data_exchange_mode'] ?? null,
            'api_profile_id' => $data['api_profile_id'] ?? null, 'source_document_id' => $data['source_document_id'] ?? null,
            'data_status' => ! empty($data['is_demo']) ? 'DEMO' : 'PENDING_PRIVATE_SOURCE',
        ], fn ($v) => $v !== null);
    }

    public function find(string $id): object
    {
        $a = DB::table('carrier_broker_agreements')->find($id) ?? throw ValidationException::withMessages(['agreement' => 'Unknown agreement.']);
        $a->territories = json_decode((string) $a->territories, true);
        $a->channels = json_decode((string) $a->channels, true);
        $a->products = DB::table('carrier_broker_agreement_products')->where('agreement_id', $id)->orderBy('line_code')->get();
        $a->authority_limits = DB::table('authority_limits')->where('carrier_broker_agreement_id', $id)->get(['id', 'authority_type', 'line_code', 'max_amount_minor', 'currency', 'status']);

        return $a;
    }

    private function commission(object $line): ?int
    {
        if ($line->commission_basis_points !== null) {
            return (int) $line->commission_basis_points;
        }
        if ($line->commission_rule_version_id !== null) {
            return (int) DB::table('commission_rule_versions')->where('id', $line->commission_rule_version_id)->value('basis_points');
        }

        return null;
    }
}
