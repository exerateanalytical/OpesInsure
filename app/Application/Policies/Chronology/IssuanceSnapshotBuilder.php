<?php

namespace App\Application\Policies\Chronology;

use App\Models\Policy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * REQ-POL-003 — structured policy snapshot per PRE §84 (schema 1):
 * version, coverages, limits, deductibles, premium, taxes, fees, risk, rules,
 * documents, beneficiaries (+ parties, dates, lineage, terms_hash).
 * Built only from data already frozen at issuance (proposal terms, quote facts).
 */
final class IssuanceSnapshotBuilder
{
    public const SCHEMA_VERSION = 1;

    /** @param array<string,mixed>|null $terms override (e.g. endorsement terms_after) */
    public function build(Policy $policy, int $versionNo, ?array $terms = null, ?array $authority = null): array
    {
        $terms ??= (array) $policy->terms_snapshot;
        $proposal = $policy->proposal_id ? DB::table('proposals')->where('id', $policy->proposal_id)->first() : null;
        $quote = isset($terms['quote_id']) ? DB::table('quotes')->where('id', $terms['quote_id'])->first() : null;
        $currency = $terms['currency'] ?? $policy->currency ?? 'XAF';

        $coverages = [];
        foreach ((array) ($terms['coverage_snapshot']['coverages'] ?? []) as $c) {
            $c = (array) $c;
            if (! isset($c['code'])) {
                continue;
            }
            $coverages[] = [
                'code' => (string) $c['code'],
                'name' => $c['name'] ?? null,
                'mandatory' => (bool) ($c['mandatory'] ?? false),
                'optional' => (bool) ($c['optional'] ?? false),
                'limit_minor' => isset($c['limit_minor']) ? (int) $c['limit_minor'] : null,
                'deductible_minor' => isset($c['deductible_minor']) ? (int) $c['deductible_minor'] : null,
                'premium_minor' => isset($c['premium_minor']) ? (int) $c['premium_minor'] : null,
            ];
        }

        $risks = [];
        if ($quote !== null) {
            $asset = $quote->risk_asset_id ? DB::table('risk_assets')->where('id', $quote->risk_asset_id)->first() : null;
            $risks[] = [
                'risk_type' => $asset->type ?? strtoupper((string) ($quote->line_code ?? 'GENERAL')),
                'risk_asset_id' => $asset->id ?? null,
                'display_name' => $asset->display_name ?? null,
                'facts' => json_decode((string) ($quote->risk_facts ?? '{}'), true) ?: [],
            ];
        }

        $beneficiaries = Schema::hasTable('beneficiary_designations')
            ? DB::table('beneficiary_designations')->where('policy_id', $policy->id)->where('status', 'ACTIVE')->orderBy('designation')->orderBy('full_name')
                ->get(['designation', 'party_id', 'full_name', 'relationship', 'allocation_pct'])
                ->map(fn ($b) => ['designation' => $b->designation, 'party_id' => $b->party_id, 'full_name' => $b->full_name, 'relationship' => $b->relationship, 'allocation_bp' => (int) round(((float) $b->allocation_pct) * 100)])->all()
            : [];

        $documents = array_values(array_unique(array_merge(
            (array) ($terms['coverage_snapshot']['document_ids'] ?? []),
            $proposal ? DB::table('proposal_documents')->where('proposal_id', $proposal->id)->orderBy('document_id')->pluck('document_id')->all() : [],
        )));
        sort($documents);

        $premium = (int) ($terms['premium_minor'] ?? $policy->premium_minor ?? 0);

        return [
            'schema' => 'policy_snapshot',
            'schema_version' => self::SCHEMA_VERSION,
            'version' => ['policy_version' => $versionNo, 'product_id' => $terms['product_id'] ?? null, 'offer_id' => $terms['offer_id'] ?? null, 'quote_id' => $terms['quote_id'] ?? null],
            'coverages' => $coverages,
            'limits' => array_values(array_filter(array_map(fn ($c) => $c['limit_minor'] === null ? null : ['coverage_code' => $c['code'], 'limit_type' => 'PER_CLAIM', 'amount_minor' => $c['limit_minor']], $coverages))),
            'deductibles' => array_values(array_filter(array_map(fn ($c) => $c['deductible_minor'] === null ? null : ['coverage_code' => $c['code'], 'amount_minor' => $c['deductible_minor']], $coverages))),
            'premium' => ['currency' => $currency, 'net_minor' => $premium, 'total_minor' => (int) ($terms['total_minor'] ?? $policy->premium_minor ?? $premium)],
            'taxes' => ['total_minor' => (int) ($terms['tax_minor'] ?? 0)],
            'fees' => ['total_minor' => (int) ($terms['fee_minor'] ?? 0)],
            'risk' => $risks,
            'rules' => [
                'tariff_version_id' => $terms['tariff_version_id'] ?? null,
                'question_set_id' => $proposal->question_set_id ?? null,
                'disclosure_schema_version_id' => $proposal->disclosure_schema_version_id ?? null,
                'question_snapshot_hash' => $proposal->question_snapshot_hash ?? null,
                'authority' => $authority,
            ],
            'documents' => $documents,
            'beneficiaries' => $beneficiaries,
            'parties' => [['role' => 'POLICYHOLDER', 'party_id' => $policy->party_id]],
            'dates' => ['coverage_starts_at' => $policy->coverage_starts_at?->toIso8601String(), 'coverage_ends_at' => $policy->coverage_ends_at?->toIso8601String(), 'issued_at' => $policy->issued_at?->toIso8601String()],
            'previous_policy_id' => $policy->previous_policy_id,
            'terms_hash' => $policy->terms_hash,
        ];
    }
}
