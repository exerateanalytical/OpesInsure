<?php

declare(strict_types=1);

namespace App\Application\Documents\Security;

use App\Application\DocumentCatalogue\CanonicalFieldDictionary;
use App\Models\Claim;
use App\Models\PaymentIntentRecord;
use App\Models\Policy;
use App\Models\PolicyTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Field completeness of an issued document (document_implementation_policy §1.1-§1.3, the 15 field
 * groups and the detailed field specs of the 36 critical documents).
 *
 * resolve(): the canonical field values of one document, read from canonical entities only (policy,
 * party, carrier, product, quote risk facts / subject, offer, payment, claim, policy transaction).
 * requiredKeys(): what the canonical spec makes mandatory for the document type — the universal groups
 * (FG-01/02/13/15), the groups the spec names explicitly (e.g. DOC-016 "FG-01–07, FG-12–15"), and every
 * non-conditional detailed-spec bullet that maps to a canonical key. Groups derived only from prose
 * keywords are recorded but not enforced (they are an inference, not spec text).
 * missing(): required keys whose value is empty — the engine blocks issuance on them, never renders a blank.
 */
final class DocumentFieldRequirements
{
    /** @var array<string, array<string, mixed>|null> */
    private array $specs = [];

    /**
     * @param  array<string, mixed>  $type  DocumentRegister::describe()
     * @return array{required: array<int, string>, groups: array<string, string>, no_source: array<int, string>, unmapped: array<int, string>, spec_id: string|null}
     */
    public function requiredKeys(array $type, ?string $subjectType = null): array
    {
        $spec = $this->spec($type['canonical_spec_id'] ?? null);
        $groups = $spec ? (array) json_decode((string) $spec->field_group_refs, true) : array_fill_keys(CanonicalFieldDictionary::UNIVERSAL_GROUPS, 'UNIVERSAL');
        $required = [];
        foreach ($groups as $g => $how) {
            if ($how === 'DERIVED_KEYWORD') {
                continue;
            }
            array_push($required, ...(CanonicalFieldDictionary::GROUP_KEYS[$g] ?? []));
        }
        $noSource = [];
        $unmapped = [];
        foreach ($spec ? (array) json_decode((string) $spec->detailed_field_map, true) : [] as $f) {
            match ($f['status']) {
                'ENFORCED' => array_push($required, ...(array) $f['target']),
                'NO_CANONICAL_SOURCE' => $noSource[] = $f['bullet'],
                'UNMAPPED_PENDING_VERIFICATION' => $unmapped[] = $f['bullet'],
                default => null,
            };
        }
        // FG-05 is dynamic by class (field group subgroups): a vehicle document identifies the vehicle.
        if ($subjectType === 'VEHICLE' && isset($groups['FG-05']) && $groups['FG-05'] !== 'DERIVED_KEYWORD') {
            $required[] = 'risk.registration_number';
        }
        $required = array_values(array_unique($required));
        sort($required);

        return ['required' => $required, 'groups' => $groups, 'no_source' => array_values(array_unique($noSource)), 'unmapped' => array_values(array_unique($unmapped)), 'spec_id' => $spec?->spec_id];
    }

    /**
     * Canonical field values of one document. Values are strings, numbers or lists; empty = null.
     *
     * @param  array<string, mixed>  $base  document-level values already fixed by the engine (number, title, issue time, verification ...)
     * @param  array<string, mixed>  $subjectFacts
     * @param  array{transaction?: PolicyTransaction, claim?: Claim, payment?: PaymentIntentRecord}  $ctx
     * @return array<string, mixed>
     */
    public function resolve(Policy $policy, array $base, ?array $subject, array $subjectFacts, array $ctx): array
    {
        $offer = $policy->proposal?->offer;
        $product = $offer?->product;
        $facts = $subjectFacts;
        $payment = $ctx['payment'] ?? ($policy->payment_intent_id ? PaymentIntentRecord::find($policy->payment_intent_id) : null);
        $paid = $payment && $payment->status === 'SUCCEEDED';
        $claim = $ctx['claim'] ?? null;
        $tx = $ctx['transaction'] ?? null;
        $coverages = array_values(array_filter((array) ($policy->terms_snapshot['coverage_snapshot']['coverages'] ?? $offer?->coverage_snapshot['coverages'] ?? []),
            fn ($c) => is_array($c) ? ! empty($c['name'] ?? $c['code'] ?? null) : ! empty($c)));
        $taxes = $offer && ($offer->tax_minor !== null || $offer->fee_minor !== null) ? (int) $offer->tax_minor + (int) $offer->fee_minor : null;
        $risk = $subject['label'] ?? null;
        if (! $risk && $facts !== []) {
            $scalar = array_filter($facts, fn ($v, $k) => is_scalar($v) && $v !== '' && ! str_starts_with((string) $k, '_'), ARRAY_FILTER_USE_BOTH);
            $risk = $scalar ? implode(', ', array_map(fn ($k, $v) => str_replace('_', ' ', (string) $k).': '.$v, array_keys(array_slice($scalar, 0, 6, true)), array_slice($scalar, 0, 6, true))) : null;
        }
        if (! $risk && in_array(strtoupper((string) ($product?->line_code ?? '')), ['LIFE', 'HEALTH', 'PA', 'ACCIDENT', 'TRAVEL', 'FUNERAL'], true)) {
            $risk = $policy->party?->display_name; // person lines: the insured person is the risk
        }

        return $base + [
            'issuer.legal_name' => $base['issuer.legal_name'] ?? null,
            'party.name' => $policy->party?->display_name,
            'policy.number' => $policy->policy_number,
            'policy.insurer' => $policy->carrier?->party?->display_name,
            'policy.product' => $product?->name,
            'policy.insurance_class' => $product?->line_code,
            'policy.effective_from' => $policy->coverage_starts_at?->toIso8601String(),
            'policy.effective_until' => $policy->coverage_ends_at?->toIso8601String(),
            'policy.currency' => $policy->currency,
            'policy.version' => $policy->version,
            'risk.summary' => $risk,
            'risk.registration_number' => $facts['registration_number'] ?? $facts['plate'] ?? null,
            'risk.vin' => $facts['vin'] ?? $facts['chassis_number'] ?? null,
            'risk.make' => $facts['make'] ?? $facts['vehicle_make'] ?? null,
            'risk.model' => $facts['model'] ?? $facts['vehicle_model'] ?? null,
            'risk.usage' => $facts['usage'] ?? $facts['vehicle_use'] ?? $facts['use'] ?? null,
            'risk.model_year' => $facts['model_year'] ?? $facts['year'] ?? null,
            'coverage.lines' => $coverages ?: null,
            'premium.gross' => $policy->premium_minor,
            'premium.currency' => $policy->currency,
            'premium.taxes' => $taxes,
            'payment.reference' => $paid ? $payment->provider_reference : null,
            'payment.amount' => $paid ? $payment->amount_minor : null,
            'payment.paid_at' => $paid ? ($payment->reconciled_at ?? $payment->updated_at)?->toIso8601String() : null,
            'payment.method' => $paid ? $payment->provider : null,
            'payment.status' => $payment ? ($paid ? 'PAID' : $payment->status) : null,
            'claim.number' => $claim?->claim_number,
            'claim.loss_date' => $claim?->loss_occurred_at?->toIso8601String(),
            'claim.status' => $claim?->status,
            'endorsement.number' => $tx?->transaction_number,
            'endorsement.effective_at' => $tx?->effective_at?->toIso8601String(),
            'endorsement.changes' => $tx ? ((array) $tx->requested_changes ?: null) : null,
            'member.reference' => $subject && ($subject['type'] ?? null) === 'MEMBER' ? $subject['key'] : null,
            'provider.name' => null, 'treaty.reference' => null, 'reinsurer.name' => null, // no engine trigger issues provider / reinsurance documents yet
        ];
    }

    /**
     * @param  array<int, string>  $required
     * @param  array<string, mixed>  $values
     * @return array<int, string> missing keys
     */
    public static function missing(array $required, array $values): array
    {
        return array_values(array_filter($required, function (string $k) use ($values): bool {
            $v = $values[$k] ?? null;

            return $v === null || $v === '' || $v === [];
        }));
    }

    /** @param array<int, string> $keys */
    public static function describeMissing(array $keys): string
    {
        return implode(', ', array_map(fn ($k) => (CanonicalFieldDictionary::KEYS[$k][0] ?? $k).' ('.$k.')', $keys));
    }

    private function spec(?string $specId): ?object
    {
        if (! $specId) {
            return null;
        }
        if (! array_key_exists($specId, $this->specs)) {
            $this->specs[$specId] = Schema::hasTable('document_canonical_specs') ? DB::table('document_canonical_specs')->where('spec_id', $specId)->first() : null;
        }

        return $this->specs[$specId] ? (object) $this->specs[$specId] : null;
    }
}
