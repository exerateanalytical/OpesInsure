<?php

declare(strict_types=1);

namespace App\Application\Documents\Security;

use App\Models\DocumentTemplate;
use App\Models\Policy;

/**
 * Security Matrix v1 §9 "Security Issuance Gate": the 15 checks run for every engine-issued document.
 *
 * Step statuses:
 *  PASS                  satisfied;
 *  FAIL                  not satisfied: issuance is always refused (a coded reason, never a blank document);
 *  CONFIG_REQUIRED       depends on something the platform does not have yet (signing key, maker-checker evidence,
 *                        verified corporate seal artwork, secure stock / hologram serials) — blocks only when
 *                        document_security.enforce_controls is on;
 *  PENDING_VERIFICATION  a value without a verified source (field enforcement in "record" mode) — same rule;
 *  NOT_APPLICABLE        not required for this document;
 *  DEFERRED              post-numbering step (7–9, 12–15), set by finalize() once performed.
 *
 * evaluate() runs before numbering (steps 1–6, 10, 11) so that a refused document consumes no number.
 */
final class IssuanceGate
{
    public const STEPS = [
        1 => 'SOURCE_TRANSACTION_EXISTS', 2 => 'SOURCE_STATUS_PERMITS_ISSUANCE', 3 => 'ISSUER_AUTHORIZED', 4 => 'TEMPLATE_VERSION_ACTIVE',
        5 => 'REQUIRED_FIELDS_COMPLETE', 6 => 'MAKER_CHECKER_COMPLETED', 7 => 'DOCUMENT_NUMBER_RESERVED', 8 => 'HASH_GENERATED',
        9 => 'VERIFICATION_TOKEN_GENERATED', 10 => 'SEAL_SIGNATURE_AUTHORITY_VALID', 11 => 'SECURE_STOCK_SERIAL_ASSIGNED', 12 => 'FINAL_PDF_RENDERED',
        13 => 'FINAL_HASH_VERIFIED', 14 => 'AUDIT_EVENT_PERSISTED', 15 => 'DOCUMENT_MARKED_ISSUED',
    ];

    /** Policy states from which nothing may be issued (a refused / voided contract). */
    public const NON_ISSUABLE_POLICY_STATUSES = ['VOID', 'REJECTED', 'DECLINED'];

    /**
     * @param  array<string, mixed>  $security  DocumentSecurityProfile::resolve()
     * @param  array{issuer: string, issuer_authorized: bool, claim?: mixed, transaction?: mixed, missing_fields: list<string>, field_enforcement: string, physical_issuance?: bool}  $in
     * @return array{steps: array<string, array{step: int, check: string, status: string, reason: string|null}>, blocking: list<string>, deferred: list<string>}
     */
    public static function evaluate(?Policy $policy, ?DocumentTemplate $template, array $security, array $in): array
    {
        $c = $security['controls'] ?? [];
        $s = [];
        $set = function (int $n, string $status, ?string $reason = null) use (&$s): void {
            $s[sprintf('GATE-%02d', $n)] = ['step' => $n, 'check' => self::STEPS[$n], 'status' => $status, 'reason' => $reason];
        };

        // 1. Source transaction exists.
        $claim = $in['claim'] ?? null;
        $tx = $in['transaction'] ?? null;
        // D4: provider documents without a policy name their provider event as the source (DocumentEngine::issueProviderDocument).
        $sourced = ($policy && $policy->exists) || ! empty($in['provider_source']);
        $set(1, $sourced ? 'PASS' : 'FAIL', $sourced ? null : 'SOURCE_POLICY_MISSING');
        $gone = fn ($m) => $m instanceof \Illuminate\Database\Eloquent\Model && ! $m->exists;
        if ($gone($claim) || $gone($tx)) {
            $set(1, 'FAIL', 'SOURCE_TRANSACTION_MISSING');
        }

        // 2. Source status permits issuance.
        $status = (string) ($policy?->status ?? '');
        $set(2, in_array($status, self::NON_ISSUABLE_POLICY_STATUSES, true) ? 'FAIL' : 'PASS',
            in_array($status, self::NON_ISSUABLE_POLICY_STATUSES, true) ? 'SOURCE_STATUS_'.$status : null);
        if ($tx !== null && isset($tx->status) && in_array($tx->status, ['DRAFT', 'REJECTED', 'CANCELLED', 'WITHDRAWN'], true)) {
            $set(2, 'FAIL', 'TRANSACTION_STATUS_'.$tx->status);
        }

        // 3. Issuer authorized.
        $set(3, $in['issuer_authorized'] ? 'PASS' : 'FAIL', $in['issuer_authorized'] ? null : 'ISSUER_NOT_AUTHORIZED:'.$in['issuer']);

        // 4. Template version active (published and inside its effective window).
        $today = now()->toDateString();
        $active = $template && $template->status === 'PUBLISHED'
            && (! $template->effective_from || (string) \Illuminate\Support\Carbon::parse($template->effective_from)->toDateString() <= $today)
            && (! $template->effective_until || (string) \Illuminate\Support\Carbon::parse($template->effective_until)->toDateString() >= $today);
        $set(4, $active ? 'PASS' : 'FAIL', $active ? null : 'TEMPLATE_NOT_ACTIVE'.($template ? ':'.$template->code.' v'.$template->version.' '.$template->status : ''));

        // 5. Required fields complete.
        $missing = $in['missing_fields'];
        $set(5, $missing === [] ? 'PASS' : ($in['field_enforcement'] === 'block' ? 'FAIL' : 'PENDING_VERIFICATION'), $missing === [] ? null : 'MISSING_FIELDS:'.implode(',', $missing));

        // 6. Maker-checker completed where required.
        $mc = (string) ($c['maker_checker']['status'] ?? 'NOT_APPLICABLE');
        $set(6, match ($mc) { 'APPLIED' => 'PASS', 'CONFIG_REQUIRED' => 'CONFIG_REQUIRED', default => 'NOT_APPLICABLE' }, $mc === 'CONFIG_REQUIRED' ? 'MAKER_CHECKER_EVIDENCE_MISSING' : null);

        foreach ([7, 8, 9, 12, 13, 14, 15] as $n) {
            $set($n, 'DEFERRED');
        }

        // 10. Seal / signature authority valid.
        $reasons = [];
        if (($c['signature']['status'] ?? null) === 'CONFIG_REQUIRED') {
            $reasons[] = 'SIGNING_KEY_NOT_PROVISIONED';
        }
        if (($c['seal']['profiles']['SEAL-01']['status'] ?? null) === 'CONFIG_REQUIRED') {
            $reasons[] = 'CORPORATE_SEAL_ARTWORK_NOT_VERIFIED';
        }
        $sealOn = ($c['seal']['status'] ?? null) === 'APPLIED' || ($c['signature']['status'] ?? null) === 'APPLIED';
        $set(10, $reasons !== [] ? 'CONFIG_REQUIRED' : ($sealOn ? 'PASS' : 'NOT_APPLICABLE'), $reasons ? implode(',', $reasons) : null);

        // 11. Secure stock / hologram serial assigned where required (only when physical issuance applies).
        $physical = [];
        foreach (['uv', 'hologram', 'secure_stock'] as $p) {
            if (($c[$p]['status'] ?? null) === 'CONFIG_REQUIRED') {
                $physical[] = $p;
            }
        }
        $needsPhysical = ($security['tier'] ?? null) === 'S5';
        $set(11, ! $needsPhysical ? 'NOT_APPLICABLE' : ($physical === [] ? 'PASS' : 'CONFIG_REQUIRED'), $needsPhysical && $physical ? 'SERIAL_INVENTORY_NOT_PROVISIONED:'.implode(',', $physical) : null);

        ksort($s);

        return self::summarize($s);
    }

    /**
     * Post-numbering steps 7–9 and 12–15, from what the engine actually did.
     *
     * @param  array{steps: array<string, array<string, mixed>>}  $gate
     * @param  array{number: ?string, content_hash: ?string, token_hash: ?string, pdf_bytes: ?string, sha256: ?string, stored_bytes: ?string, in_transaction: bool, status: string}  $f
     */
    public static function finalize(array $gate, array $f): array
    {
        $s = $gate['steps'];
        $put = function (int $n, bool $ok, string $fail) use (&$s): void {
            $s[sprintf('GATE-%02d', $n)]['status'] = $ok ? 'PASS' : 'FAIL';
            $s[sprintf('GATE-%02d', $n)]['reason'] = $ok ? null : $fail;
        };
        $put(7, (string) $f['number'] !== '', 'NUMBER_NOT_RESERVED');
        $put(8, strlen((string) $f['content_hash']) === 64, 'HASH_NOT_GENERATED');
        $put(9, strlen((string) $f['token_hash']) === 64, 'TOKEN_NOT_GENERATED');
        $put(12, str_starts_with((string) $f['pdf_bytes'], '%PDF'), 'PDF_NOT_RENDERED');
        $put(13, $f['sha256'] !== null && $f['stored_bytes'] !== null && hash_equals((string) $f['sha256'], hash('sha256', (string) $f['stored_bytes'])), 'FINAL_HASH_MISMATCH');
        // The audit event is written in the same DB transaction as the registry record: a failed audit rolls the issuance back.
        $put(14, $f['in_transaction'], 'AUDIT_NOT_ATOMIC');
        if ($f['in_transaction']) {
            $s['GATE-14']['reason'] = 'ATOMIC_WITH_REGISTRY_RECORD';
        }
        $put(15, in_array($f['status'], ['ISSUED', 'VALID', 'PENDING_SIGNATURE'], true), 'STATUS_NOT_ISSUED');
        if ($f['status'] === 'PENDING_SIGNATURE') {
            $s['GATE-15']['reason'] = 'ISSUED_PENDING_SIGNATURE';
        }

        return self::summarize($s);
    }

    /**
     * Whether the gate refuses issuance, and the coded reasons.
     *
     * @return list<string>
     */
    public static function refusals(array $gate, bool $enforceControls): array
    {
        $out = [];
        foreach ($gate['steps'] as $code => $st) {
            if ($st['status'] === 'FAIL' || ($enforceControls && in_array($st['status'], ['CONFIG_REQUIRED', 'PENDING_VERIFICATION'], true))) {
                $out[] = $code.':'.$st['check'].($st['reason'] ? ':'.$st['reason'] : '');
            }
        }

        return $out;
    }

    private static function summarize(array $s): array
    {
        $by = fn (array $st) => array_keys(array_filter($s, fn ($x) => in_array($x['status'], $st, true)));

        return ['steps' => $s, 'failed' => $by(['FAIL']), 'config_required' => $by(['CONFIG_REQUIRED', 'PENDING_VERIFICATION']), 'deferred' => $by(['DEFERRED']),
            'passed' => count(array_filter($s, fn ($x) => in_array($x['status'], ['PASS', 'NOT_APPLICABLE'], true)))];
    }
}
