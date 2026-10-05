<?php

declare(strict_types=1);

namespace App\Application\Documents\Security;

use App\Application\DocumentCatalogue\DetailedFieldSourceMap;
use App\Models\Policy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * D2 mapped field rules (DetailedFieldSourceMap, MAPPED_PLATFORM_SOURCE): the values the platform already holds for
 * the issuance context. Read-only; a key whose source is empty is omitted (the shell then prints no row: never a blank
 * placeholder). Only new issuance reads these — issued documents keep their frozen snapshot.
 *
 * Two layers:
 *  1. explicit readers for derived values (policy term, coverage flags, renewal due date ...);
 *  2. a generic reader driven by the map's own `table.column` source: the row is anchored on the issuance context
 *     only — the policy / claim / proposal / quote / offer / payment / transaction / party / provider in play, and
 *     the source ids a non-policy flow passes in ctx['sources'] (DocumentEngine::issueProviderDocument). A table that
 *     cannot be anchored to this document is never read "by latest row": the key stays unresolved.
 */
final class MappedFieldValues
{
    /** Keys never printed from the generic reader (privacy-gated, hashes, or owned by Zones A/B/F). */
    private const SKIP = ['verification.token', 'party.date_of_birth', 'confidentiality.class', 'document.number', 'document.issued_at', 'issuer.legal_name'];

    /** Columns never printed (secrets, raw identity). */
    private const SECRET_COLUMNS = '/(token|hash|secret|password|legal_identity|encrypted)/';

    /** @var array<string, array<int, string>> */
    private static array $columns = [];

    /** @return array<string, mixed> */
    public static function resolve(Policy $policy, array $ctx): array
    {
        $v = self::explicit($policy, $ctx);
        $anchors = self::anchors($policy, $ctx);
        $currency = (string) ($ctx['currency'] ?? $policy->currency ?? 'XAF');
        foreach (DetailedFieldSourceMap::MAP as [$key, $source]) {
            if ($key === null || isset($v[$key]) || in_array($key, self::SKIP, true)) {
                continue;
            }
            try {
                $val = self::read((string) $source, $anchors, $currency);
            } catch (Throwable) {
                $val = null;
            }
            if ($val !== null && $val !== '' && $val !== []) {
                $v[$key] = $val;
            }
        }

        return array_filter($v, fn ($x) => $x !== null && $x !== '' && $x !== []);
    }

    /** @return array<string, string> anchor column => id */
    public static function anchors(Policy $policy, array $ctx): array
    {
        $offer = $policy->proposal?->offer;
        $claim = $ctx['claim'] ?? null;
        $tx = $ctx['transaction'] ?? null;
        $payment = $ctx['payment'] ?? null;
        $a = array_filter([
            'policy_id' => $policy->exists ? $policy->id : null,
            'proposal_id' => $policy->proposal_id ?? $offer?->proposal?->id ?? null,
            'quote_offer_id' => $offer?->id,
            'quote_id' => $offer?->quote_id,
            'party_id' => $policy->party_id,
            'carrier_id' => $policy->carrier_id ?? ($ctx['carrier_id'] ?? null),
            'product_id' => $offer?->product_id,
            'claim_id' => is_object($claim) ? $claim->id : null,
            'policy_transaction_id' => is_object($tx) ? $tx->id : null,
            'payment_intent_id' => is_object($payment) ? $payment->id : ($policy->payment_intent_id ?? null),
            'provider_profile_id' => $ctx['provider_id'] ?? null,
            'tenant_branch_id' => $ctx['branch_id'] ?? null,
        ]);
        foreach ((array) ($ctx['sources'] ?? []) as $col => $id) {
            if (is_string($col) && str_ends_with($col, '_id') && is_scalar($id) && $id !== '') {
                $a[$col] = (string) $id;
            }
        }
        // Rows reachable from an anchored row by its own foreign keys (e.g. a preauth's member / contract).
        foreach (['health_preauthorization_id' => 'health_preauthorizations', 'provider_contract_id' => 'provider_contracts', 'health_provider_claim_id' => 'health_provider_claims'] as $col => $table) {
            if (isset($a[$col]) && Schema::hasTable($table)) {
                $row = (array) DB::table($table)->where('id', $a[$col])->first();
                foreach ($row as $k => $val) {
                    if (str_ends_with($k, '_id') && is_string($val) && $val !== '' && ! isset($a[$k]) && $k !== 'tenant_id') {
                        $a[$k] = $val;
                    }
                }
            }
        }

        return $a;
    }

    /** Parses one map source ("t.c", "t.c1, c2", "t.fk -> t2.c", "t", "sum(t.c)", alternatives split by " / ") and reads it. */
    public static function read(string $source, array $anchors, string $currency): mixed
    {
        foreach (preg_split('#\s+/\s+#', $source) as $alt) {
            $sum = (bool) preg_match('/^\s*sum\(([\w.]+)\)/', $alt, $m);
            $alt = $sum ? $m[1] : trim((string) preg_replace('/\s*\(.*$/', '', $alt)); // drop "(filter)" notes
            $val = null;
            if (preg_match('/^(\w+)\.(\w+)\s*->\s*(\w+)\.(\w+)$/', $alt, $m)) {
                $fks = self::values($m[1], [$m[2]], $anchors, $currency, false, true);
                if ($fks && Schema::hasTable($m[3]) && self::hasColumn($m[3], $m[4])) {
                    $val = self::join(DB::table($m[3])->whereIn('id', array_slice($fks, 0, 20))->pluck($m[4])->all(), $m[4], $currency);
                }
            } elseif (preg_match('/^(\w+)\.(\w+(?:\s*,\s*\w+)*)$/', $alt, $m)) {
                $cols = array_map('trim', explode(',', $m[2]));
                $val = $sum ? self::sum($m[1], $cols[0], $anchors, $currency) : self::values($m[1], $cols, $anchors, $currency);
            } elseif (preg_match('/^(\w+)$/', $alt, $m)) {
                $val = self::values($m[1], ['display_name', 'full_name', 'name', 'official_name', 'reference', 'code', 'serial_number', 'status'], $anchors, $currency, true);
            }
            if ($val !== null && $val !== '' && $val !== []) {
                return $val;
            }
        }

        return null;
    }

    /** @return mixed joined display value, or (raw) the list of raw values */
    private static function values(string $table, array $cols, array $anchors, string $currency, bool $firstLabelOnly = false, bool $raw = false): mixed
    {
        $q = self::anchored($table, $anchors);
        if (! $q) {
            return null;
        }
        $cols = array_values(array_filter($cols, fn ($c) => self::hasColumn($table, $c) && ! preg_match(self::SECRET_COLUMNS, $c)));
        if ($cols === []) {
            return null;
        }
        if ($firstLabelOnly) {
            $cols = [$cols[0]];
        }
        if (self::hasColumn($table, 'created_at')) {
            $q->orderByDesc('created_at');
        }
        $rows = $q->limit(20)->get($cols);
        if ($raw) {
            return $rows->pluck($cols[0])->filter()->unique()->values()->all();
        }
        $out = [];
        foreach ($rows as $r) {
            $parts = [];
            foreach ($cols as $c) {
                $p = self::fmt($r->{$c}, $c, $currency);
                if ($p !== null) {
                    $parts[] = count($cols) > 1 ? ucwords(str_replace('_', ' ', preg_replace('/_minor$/', '', $c))).': '.$p : $p;
                }
            }
            if ($parts !== []) {
                $out[] = implode(', ', $parts);
            }
        }
        $out = array_values(array_unique($out));

        return $out === [] ? null : implode(' · ', $out);
    }

    private static function sum(string $table, string $col, array $anchors, string $currency): ?string
    {
        $q = self::anchored($table, $anchors);

        return $q && self::hasColumn($table, $col) && $q->exists() ? rtrim(rtrim(number_format((float) $q->sum($col), 2, '.', ' '), '0'), '.') : null;
    }

    private static function anchored(string $table, array $anchors): ?\Illuminate\Database\Query\Builder
    {
        if (! Schema::hasTable($table)) {
            return null;
        }
        // The anchored row itself (ctx source id / model in play).
        foreach ($anchors as $col => $id) {
            if (self::tableFor($col) === $table) {
                return DB::table($table)->where('id', $id);
            }
        }
        // Rows pointing at an anchor through their own foreign key (most specific first).
        foreach (['health_preauthorization_id', 'health_provider_claim_id', 'health_provider_settlement_batch_id', 'provider_tariff_version_id', 'provider_contract_id',
            'claim_id', 'policy_transaction_id', 'payment_intent_id', 'policy_id', 'proposal_id', 'quote_offer_id', 'quote_id', 'provider_profile_id', 'party_id', 'product_id'] as $col) {
            if (isset($anchors[$col]) && self::hasColumn($table, $col)) {
                return DB::table($table)->where($col, $anchors[$col]);
            }
        }
        foreach ($anchors as $col => $id) {
            if (self::hasColumn($table, $col) && ! in_array($col, ['carrier_id', 'tenant_id'], true)) {
                return DB::table($table)->where($col, $id);
            }
        }

        return null;
    }

    /** "health_preauthorization_id" => "health_preauthorizations", "…_batch_id" => "…_batches". */
    private static function tableFor(string $col): ?string
    {
        $base = substr($col, 0, -3);
        $aliases = ['provider_profile' => 'provider_profiles', 'party' => 'parties', 'product' => 'insurance_products', 'payment_intent' => 'payment_intents', 'carrier' => 'carriers'];
        if (isset($aliases[$base])) {
            return $aliases[$base];
        }
        foreach ([$base.'s', $base.'es', preg_replace('/y$/', 'ies', $base)] as $t) {
            if (Schema::hasTable($t)) {
                return $t;
            }
        }

        return null;
    }

    private static function hasColumn(string $table, string $col): bool
    {
        self::$columns[$table] ??= Schema::hasTable($table) ? Schema::getColumnListing($table) : [];

        return in_array($col, self::$columns[$table], true);
    }

    private static function join(array $vals, string $col, string $currency): ?string
    {
        $out = array_values(array_unique(array_filter(array_map(fn ($x) => self::fmt($x, $col, $currency), $vals), fn ($x) => $x !== null)));

        return $out === [] ? null : implode(' · ', $out);
    }

    private static function fmt(mixed $x, string $col, string $currency): ?string
    {
        if ($x === null || $x === '') {
            return null;
        }
        if (is_bool($x)) {
            return $x ? 'Yes / Oui' : 'No / Non';
        }
        if (str_ends_with($col, '_minor') && is_numeric($x)) {
            return self::money((int) $x, $currency);
        }
        if (str_ends_with($col, '_bp') && is_numeric($x)) {
            return rtrim(rtrim(number_format(((int) $x) / 100, 2, '.', ''), '0'), '.').' %';
        }
        if (is_string($x) && ($x[0] ?? '') !== '' && in_array($x[0], ['{', '['], true)) {
            $d = json_decode($x, true);
            if (is_array($d)) {
                $flat = [];
                array_walk_recursive($d, function ($v, $k) use (&$flat) {
                    if (is_scalar($v) && $v !== '' && ! preg_match(self::SECRET_COLUMNS, (string) $k)) {
                        $flat[] = (is_string($k) ? str_replace('_', ' ', $k).': ' : '').(is_bool($v) ? ($v ? 'yes' : 'no') : $v);
                    }
                });

                return $flat === [] ? null : implode(', ', array_slice($flat, 0, 12));
            }
        }

        return is_scalar($x) ? trim((string) $x) : null;
    }

    /** Printed amount (minor units): XAF reads FCFA on documents, like the rest of the platform (WebExperiences\Money::display). */
    public static function money(int $minor, string $currency): string
    {
        $code = strtoupper($currency ?: 'XAF');

        return number_format($minor / 100, 0, '.', ' ').' '.($code === 'XAF' ? 'FCFA' : $code);
    }

    /** @return array<string, mixed> derived values the table map alone cannot express */
    private static function explicit(Policy $policy, array $ctx): array
    {
        $offer = $policy->proposal?->offer;
        $coverages = (array) ($policy->terms_snapshot['coverage_snapshot']['coverages'] ?? $offer?->coverage_snapshot['coverages'] ?? []);
        $name = fn ($c) => is_array($c['name'] ?? null) ? ($c['name']['en'] ?? reset($c['name'])) : ($c['name'] ?? $c['code'] ?? '?');

        return array_filter([
            'coverage.mandatory_flags' => implode(' · ', array_filter(array_map(fn ($c) => is_array($c) && isset($c['mandatory']) ? $name($c).' ('.($c['mandatory'] ? 'mandatory / obligatoire' : 'optional / facultative').')' : null, $coverages))) ?: null,
            'policy.term' => $policy->coverage_starts_at && $policy->coverage_ends_at ? $policy->coverage_starts_at->format('d/m/Y').' → '.$policy->coverage_ends_at->format('d/m/Y') : null,
            'policy.certificates' => $policy->certificate_number,
            'payment.amount_words' => ($pay = $ctx['payment'] ?? ($policy->payment_intent_id ? DB::table('payment_intents')->where('id', $policy->payment_intent_id)->first() : null)) && isset($pay->amount_minor)
                ? \App\Application\Shared\AmountInWords::bilingual((int) $pay->amount_minor, (string) ($pay->currency ?? $policy->currency ?? 'XAF')) : null,
        ] + self::issued($policy, $ctx, $coverages, $name), fn ($x) => $x !== null && $x !== '' && $x !== []);
    }

    /**
     * R9: template keys of the documents the platform's real triggers issue (policy pack, claim registration,
     * cancellation / reinstatement / endorsement, premium receipt) whose value the platform holds under another
     * name. Money stays in minor units (int) so the shell prints it in FCFA; dates stay ISO so the shell formats them.
     *
     * @param  array<int, mixed>  $coverages
     * @return array<string, mixed>
     */
    private static function issued(Policy $policy, array $ctx, array $coverages, callable $name): array
    {
        $offer = $policy->proposal?->offer;
        $product = $offer?->product;
        $claim = is_object($ctx['claim'] ?? null) ? $ctx['claim'] : null;
        $tx = is_object($ctx['transaction'] ?? null) ? $ctx['transaction'] : null;
        $payment = $ctx['payment'] ?? ($policy->payment_intent_id ? DB::table('payment_intents')->where('id', $policy->payment_intent_id)->first() : null);
        $iso = fn ($d) => $d ? \Carbon\Carbon::parse($d)->toIso8601String() : null;
        $rules = fn ($r) => is_array($r) && $r !== [] ? $r : null;
        $exclusions = (array) ($policy->terms_snapshot['coverage_snapshot']['exclusions'] ?? $offer?->coverage_snapshot['exclusions'] ?? []);

        $v = [
            // The policyholder is the insured unless the platform records another insured person.
            'insured.name' => $policy->party?->display_name,
            'product.code' => $product?->code,
            'product.version' => $product?->version !== null ? 'v'.$product->version : null,
            'policy.status' => $policy->status,
            'coverage.status' => $policy->status,
            'policy.issued_at' => $iso($policy->issued_at),
            'policy.renewal_date' => $iso($policy->coverage_ends_at),
            'policy.exclusions_reference' => implode(' · ', array_filter(array_map(fn ($e) => is_array($e) ? ($e['name'] ?? $e['code'] ?? null) : (is_scalar($e) ? (string) $e : null), $exclusions))) ?: null,
            'coverage.deductibles' => implode(' · ', array_filter(array_map(fn ($c) => is_array($c) && (int) ($c['deductible_minor'] ?? 0) > 0
                ? $name($c).': '.self::money((int) $c['deductible_minor'], (string) ($policy->currency ?: 'XAF')) : null, $coverages))) ?: null,
            'policy.renewal_terms' => $rules($product?->renewal_rules),
            'policy.cancellation_rules' => $rules($product?->cancellation_rules),
            // Launch review key check: the issuing branch the platform records on the policy.
            'policy.branch' => ! empty($policy->branch_id) ? DB::table('tenant_branches')->where('id', $policy->branch_id)->value('name') : null,
            'policy.status_as_of' => $policy->status ? $policy->status.' — '.now()->format('d/m/Y') : null,
            'policy.current_effective_dates' => $policy->coverage_starts_at && $policy->coverage_ends_at ? $policy->coverage_starts_at->format('d/m/Y').' → '.$policy->coverage_ends_at->format('d/m/Y') : null,
            'policy.documents_issued' => $policy->exists ? (implode(' · ', DB::table('documents')->where('policy_id', $policy->id)->whereIn('status', ['ISSUED', 'VALID'])
                ->whereNotNull('document_number')->orderBy('created_at')->limit(20)->pluck('document_number')->all()) ?: null) : null,
            'renewal.new_policy' => $policy->exists ? Policy::where('previous_policy_id', $policy->id)->value('policy_number') : null,
        ];
        if ($claim) {
            $v['claim.submission_date'] = $iso($claim->submitted_at ?? null);
        }

        if ($claim) {
            $v += [
                'claim.claimant' => $claim->claimant_party_id ? DB::table('parties')->where('id', $claim->claimant_party_id)->value('display_name') : null,
                'claim.received_at' => $iso($claim->submitted_at ?? $claim->created_at),
                'claim.loss_description' => is_array($claim->loss_details) ? ($claim->loss_details['description'] ?? null) : null,
                'claim.loss_place' => $claim->loss_location,
                'claim.handler_contact' => $claim->assigned_to ? DB::table('users')->where('id', $claim->assigned_to)->value('full_name') : null,
                'claim.closure_date' => $iso($claim->closed_at),
            ];
            // Claim decision / settlement offer: the approved decision (heads, reasons) and its settlement when calculated.
            $decision = DB::table('claim_decisions')->where('claim_id', $claim->id)->where('status', 'APPROVED')->orderByDesc('approved_at')->first();
            $settlement = Schema::hasTable('claim_settlements') ? DB::table('claim_settlements')->where('claim_id', $claim->id)->orderByDesc('created_at')->first() : null;
            if ($decision || $settlement) {
                $heads = array_values(array_filter((array) json_decode((string) ($decision->heads ?? '[]'), true), 'is_array'));
                $cur = (string) ($settlement->currency ?? $decision->currency ?? $policy->currency ?? 'XAF');
                $paid = (int) DB::table('claim_payments')->where('claim_id', $claim->id)->where('status', 'PAID')->sum('amount_minor');
                $payee = $settlement?->payee_party_id ? DB::table('parties')->where('id', $settlement->payee_party_id)->value('display_name') : null;
                $v += array_filter([
                    'settlement.gross' => isset($settlement->gross_minor) ? (int) $settlement->gross_minor : (isset($decision->approved_amount_minor) ? (int) $decision->approved_amount_minor : null),
                    'settlement.amount' => isset($settlement->amount_minor) ? (int) $settlement->amount_minor : (isset($decision->approved_amount_minor) ? (int) $decision->approved_amount_minor : null),
                    'settlement.prior_payments' => isset($settlement->prior_payments_minor) ? (int) $settlement->prior_payments_minor : $paid,
                    'settlement.excluded' => isset($settlement->excluded_minor) ? (int) $settlement->excluded_minor : (($decision->decision ?? null) === 'APPROVE' ? 0 : null),
                    'settlement.reference' => $settlement->reference ?? $claim->claim_number,
                    'settlement.payee' => $payee ?? ($claim->claimant_party_id ? DB::table('parties')->where('id', $claim->claimant_party_id)->value('display_name') : null),
                    'settlement.breakdown' => $heads !== [] ? implode(' · ', array_map(fn ($h) => ucwords(strtolower(str_replace('_', ' ', (string) ($h['head'] ?? '')))).': '.self::money((int) ($h['amount_minor'] ?? 0), $cur), $heads)) : null,
                    'claim.coverage_check' => $decision ? trim(implode(', ', (array) json_decode((string) ($decision->reason_codes ?? '[]'), true) ?: [(string) $decision->reason_code]).' — '.(string) $decision->rationale, ' —') : null,
                ], fn ($x) => $x !== null && $x !== '');
            }
            if ($claim instanceof \App\Models\Claim) {
                try {
                    $evidence = app(\App\Application\Claims\Evidence\ClaimEvidenceRules::class)->forClaim($claim)['rules'];
                    $label = fn (array $r) => trim(($r['name_fr'] ?? '').' / '.($r['name_en'] ?? ''), ' /') ?: ($r['canonical_code'] ?? null);
                    $v['claim.requirements'] = implode(' · ', array_filter(array_map(fn ($r) => $r['mandatory'] ? $label($r) : null, $evidence))) ?: null;
                    $v['claim.conditional_requirements'] = implode(' · ', array_filter(array_map(fn ($r) => ! $r['mandatory'] && empty($r['waived']) ? $label($r) : null, $evidence))) ?: null;
                } catch (Throwable) {
                    // evidence rules unavailable: the rows stay unrecorded
                }
            }
        }

        if ($tx) {
            $type = (string) $tx->type;
            $cancel = $type === 'CANCELLATION' && Schema::hasTable('policy_cancellations')
                ? DB::table('policy_cancellations')->where('policy_transaction_id', $tx->id)->first() : null;
            $refund = $tx->refund_id ? DB::table('refunds')->where('id', $tx->refund_id)->value('status') : null;
            if ($type === 'CANCELLATION') {
                $v += [
                    'cancellation.reason' => $cancel->reason_code ?? $tx->reason_code,
                    'cancellation.effective_at' => $iso($cancel->effective_at ?? $tx->effective_at),
                    'cancellation.premium_balance' => $tx->refund_minor !== null ? (int) $tx->refund_minor : null,
                    'cancellation.refund_status' => $refund ?? ((int) $tx->refund_minor > 0 ? 'REFUND_DUE' : 'NO_REFUND_DUE'),
                    'cancellation.coverage_status' => $policy->status,
                ];
            }
            if ($type === 'REINSTATEMENT') {
                $v['reinstatement.effective_at'] = $iso($tx->effective_at);
            }
            if ($type === 'ENDORSEMENT') {
                $v += [
                    'endorsement.requested_change' => (array) $tx->requested_changes ?: null,
                    'endorsement.authorized_at' => $iso($tx->approved_at),
                    'endorsement.authorized_by' => $tx->approved_by ? DB::table('users')->where('id', $tx->approved_by)->value('full_name') : null,
                    'endorsement.resulting_version' => $tx->status === 'APPROVED' ? 'v'.(int) $policy->version : null,
                ];
            }
        }

        if ($payment && isset($payment->amount_minor)) {
            $channel = array_filter([(string) ($payment->provider ?? ''), (string) ($payment->request_channel ?? '')]);
            $v['billing.cashier_channel'] = $channel ? implode(' · ', array_map(fn ($c) => strtoupper($c), $channel)) : null;
            $due = $policy->proposal?->terms_snapshot['total_minor'] ?? $offer?->total_minor;
            if ($due !== null && ($payment->status ?? null) === 'SUCCEEDED') {
                $v['billing.balance'] = max(0, (int) $due - (int) $payment->amount_minor);
            }
            if (Schema::hasTable('payment_allocations')) {
                $v['billing.allocation_to_obligations'] = implode(' · ', DB::table('payment_allocations')->where('payment_intent_id', $payment->id)->whereNull('reverses_allocation_id')
                    ->orderBy('sequence')->get(['target_category', 'amount_minor', 'currency'])
                    ->map(fn ($a) => ucwords(strtolower(str_replace('_', ' ', (string) $a->target_category))).': '.self::money((int) $a->amount_minor, (string) ($a->currency ?: 'XAF')))->all()) ?: null;
            }
        }

        return array_filter($v, fn ($x) => $x !== null && $x !== '' && $x !== []);
    }

    /** @return array{mapped: int, source_exists: int, keys: array<int, string>} distinct mapped keys, and those whose source table exists */
    public static function coverageUniverse(): array
    {
        $keys = [];
        foreach (DetailedFieldSourceMap::MAP as [$key, $source]) {
            if ($key === null || in_array($key, self::SKIP, true)) {
                continue;
            }
            $exists = false;
            foreach (preg_split('#\s+/\s+#', (string) $source) as $alt) {
                if (preg_match('/^(?:sum\()?(\w+)/', trim($alt), $m) && Schema::hasTable($m[1])) {
                    $exists = true;
                }
            }
            $keys[$key] = ($keys[$key] ?? false) || $exists;
        }

        return ['mapped' => count($keys), 'source_exists' => count(array_filter($keys)), 'keys' => array_keys(array_filter($keys))];
    }
}
