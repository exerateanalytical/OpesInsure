<?php

declare(strict_types=1);

namespace App\Application\Compliance\Catalogue;

use App\Application\Audit\AuditWriter;
use App\Application\DataReadiness\DataStatus;
use App\Application\DataReadiness\ProductionUseGuard;
use App\Models\RiskAlert;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Gap Closure Pack 08/09 behaviour over the catalogue tables:
 *  - KYC refresh policies: tenant configures months + policy reference (maker), a different user approves (checker) → VERIFIED.
 *    monthsFor() only ever returns a VERIFIED value; placeholder rows stay CONFIG_REQUIRED and return null.
 *  - Fraud indicators: flag() opens a review (risk_alerts, FRAUD_INDICATOR_REVIEW). An indicator is never a determination.
 *  - Controls: assessments are append-only; a rated status (COMPLIANT / PARTIALLY_COMPLIANT / NON_COMPLIANT) is refused
 *    until the control's requirement is imported from its official source (VERIFIED) — CIMA 010-24 gate.
 *  - Regulatory dictionary: assertReportUsable() refuses a report with no VERIFIED dictionary line.
 */
final class ComplianceCatalogueService
{
    public const RISK_LEVELS = ['LOW', 'STANDARD', 'ELEVATED', 'HIGH', 'PROHIBITED_OR_ESCALATE'];

    /** Platform KYC rating vocabulary (CustomerRiskRatingService) => pack risk level. */
    public const RISK_ALIASES = ['MEDIUM' => 'STANDARD'];

    public const CONTROL_STATUSES = ['NOT_ASSESSED', 'COMPLIANT', 'PARTIALLY_COMPLIANT', 'NON_COMPLIANT', 'NOT_APPLICABLE', 'REMEDIATION_OPEN'];

    public const RATED_STATUSES = ['COMPLIANT', 'PARTIALLY_COMPLIANT', 'NON_COMPLIANT'];

    public const TRIGGER_EVENTS = ['ONBOARDING', 'BEFORE_BIND', 'PERIODIC_RESCREEN', 'PROFILE_CHANGE', 'PAYMENT_OR_PAYOUT_TRIGGER', 'MANUAL_RESCREEN'];

    public const INDICATOR_ALERT = 'FRAUD_INDICATOR_REVIEW';

    public function __construct(private readonly AuditWriter $audit) {}

    public static function level(string $rating): string
    {
        $r = strtoupper($rating);

        return self::RISK_ALIASES[$r] ?? $r;
    }

    // ------------------------------------------------------------------ KYC refresh

    public function refreshPolicies(?string $tenantId): array
    {
        $rows = DB::table('kyc_refresh_policies')->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $tenantId))
            ->orderBy('risk_level')->orderByDesc('created_at')->get();

        return collect(self::RISK_LEVELS)->map(function ($l) use ($rows, $tenantId) {
            $tenant = $rows->first(fn ($r) => $r->tenant_id === $tenantId && $tenantId !== null && $r->risk_level === $l);
            $platform = $rows->first(fn ($r) => $r->tenant_id === null && $r->risk_level === $l);
            $p = $tenant ?? $platform;

            return ['risk_level' => $l, 'policy' => $p ? $this->decode($p) : null, 'production_usable' => $p !== null && DataStatus::isProduction($p->data_status)];
        })->all();
    }

    public function configureRefresh(string $tenantId, string $level, array $d, User $maker): object
    {
        $level = self::level($level);
        if (! in_array($level, self::RISK_LEVELS, true)) {
            throw ValidationException::withMessages(['risk_level' => 'Unknown risk level.']);
        }
        $bad = array_diff((array) ($d['trigger_events'] ?? []), self::TRIGGER_EVENTS);
        if ($bad !== []) {
            throw ValidationException::withMessages(['trigger_events' => 'Unknown trigger event(s): '.implode(', ', $bad)]);
        }
        $id = (string) Str::uuid();
        DB::table('kyc_refresh_policies')->insert(['id' => $id, 'tenant_id' => $tenantId, 'risk_level' => $level, 'refresh_months' => (int) $d['refresh_months'],
            'trigger_events' => json_encode(array_values((array) ($d['trigger_events'] ?? []))), 'source_policy_id' => $d['source_policy_id'],
            'data_status' => DataStatus::UNVERIFIED, 'source' => 'TENANT_POLICY', 'effective_from' => $d['effective_from'] ?? now()->toDateString(),
            'effective_until' => $d['effective_until'] ?? null, 'configured_by' => $maker->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit->record('kyc.refresh_policy.configured', 'kyc_refresh_policy', $id, ['risk_level' => $level, 'refresh_months' => (int) $d['refresh_months']], null, ['tenant_id' => $tenantId, 'actor_id' => $maker->id]);

        return $this->decode(DB::table('kyc_refresh_policies')->find($id));
    }

    public function approveRefresh(string $tenantId, string $id, User $checker): object
    {
        $p = DB::table('kyc_refresh_policies')->where('tenant_id', $tenantId)->where('id', $id)->first() ?? abort(404);
        if ($p->configured_by === $checker->id) {
            throw ValidationException::withMessages(['approver' => 'Maker-checker: the configuring user cannot approve.']);
        }
        if ($p->data_status !== DataStatus::UNVERIFIED) {
            throw ValidationException::withMessages(['status' => 'Only a pending policy can be approved.']);
        }
        DB::transaction(function () use ($p, $checker, $tenantId) {
            DB::table('kyc_refresh_policies')->where('tenant_id', $tenantId)->where('risk_level', $p->risk_level)->where('id', '!=', $p->id)
                ->where('data_status', DataStatus::VERIFIED)->whereNull('effective_until')->update(['effective_until' => now()->toDateString(), 'data_status' => DataStatus::RETIRED, 'updated_at' => now()]);
            DB::table('kyc_refresh_policies')->where('id', $p->id)->update(['data_status' => DataStatus::VERIFIED, 'approved_by' => $checker->id, 'approved_at' => now(), 'updated_at' => now()]);
        });
        $this->audit->record('kyc.refresh_policy.approved', 'kyc_refresh_policy', $id, ['risk_level' => $p->risk_level], null, ['tenant_id' => $tenantId, 'actor_id' => $checker->id]);

        return $this->decode(DB::table('kyc_refresh_policies')->find($id));
    }

    /** VERIFIED refresh period for the tenant and rating, or null (not configured: never a default). */
    public function monthsFor(?string $tenantId, string $rating): ?int
    {
        if ($tenantId === null) {
            return null;
        }
        $today = now()->toDateString();
        $m = DB::table('kyc_refresh_policies')->where('tenant_id', $tenantId)->where('risk_level', self::level($rating))
            ->where('data_status', DataStatus::VERIFIED)->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $today))
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', $today))
            ->orderByDesc('approved_at')->value('refresh_months');

        return $m === null ? null : (int) $m;
    }

    // ------------------------------------------------------------------ fraud indicators

    public function flag(string $tenantId, string $code, string $subjectType, string $subjectId, array $facts, User $actor): RiskAlert
    {
        $i = DB::table('fraud_indicators')->where('code', $code)->first() ?? abort(404);
        $score = ['LOW' => 25, 'MEDIUM' => 50, 'HIGH' => 75, 'CRITICAL' => 100][$i->severity] ?? 50;
        $key = hash('sha256', implode('|', [$tenantId, $code, $subjectType, $subjectId]));
        $alert = RiskAlert::where('tenant_id', $tenantId)->where('idempotency_key', $key)->where('status', 'OPEN')->first()
            ?? RiskAlert::create(['tenant_id' => $tenantId, 'subject_type' => $subjectType, 'subject_id' => $subjectId, 'alert_type' => self::INDICATOR_ALERT,
                'risk_score' => $score, 'severity' => $i->severity, 'status' => 'OPEN', 'idempotency_key' => $key, 'payload_hash' => hash('sha256', json_encode($facts)),
                'signals' => ['indicator' => $code, 'category' => $i->category, 'facts' => $facts, 'outcome' => 'REVIEW_REQUIRED',
                    'note' => 'Indicators trigger review; they are not automatic fraud determinations.'], 'version' => 1]);
        $this->audit->record('fraud.indicator.flagged', $subjectType, $subjectId, ['indicator' => $code, 'risk_alert_id' => $alert->id], null, ['tenant_id' => $tenantId, 'actor_id' => $actor->id]);

        return $alert;
    }

    // ------------------------------------------------------------------ controls

    public function assess(string $tenantId, string $controlId, array $d, User $actor): object
    {
        $c = DB::table('compliance_controls')->find($controlId) ?? abort(404);
        if (! in_array($d['status'], self::CONTROL_STATUSES, true)) {
            throw ValidationException::withMessages(['status' => 'Unknown control status.']);
        }
        if (in_array($d['status'], self::RATED_STATUSES, true) && ! self::controlRateable($c)) {
            throw ValidationException::withMessages(['status' => "Control {$c->framework}.{$c->control_code} has no verified requirement text "
                .'(source '.($c->framework === 'ICT' ? 'CIMA Reg. 010-24' : 'AML/CFT regulation').' pending official import); only NOT_ASSESSED, NOT_APPLICABLE or REMEDIATION_OPEN can be recorded.']);
        }
        $id = (string) Str::uuid();
        DB::table('compliance_control_assessments')->insert(['id' => $id, 'tenant_id' => $tenantId, 'control_id' => $c->id, 'status' => $d['status'],
            'evidence' => json_encode(array_values((array) ($d['evidence'] ?? []))), 'notes' => $d['notes'] ?? null, 'compliance_finding_id' => $d['compliance_finding_id'] ?? null,
            'assessed_by' => $actor->id, 'assessed_at' => now(), 'created_at' => now()]);
        $this->audit->record('compliance.control.assessed', 'compliance_control', $c->id, ['status' => $d['status'], 'assessment_id' => $id], null, ['tenant_id' => $tenantId, 'actor_id' => $actor->id]);

        return DB::table('compliance_control_assessments')->find($id);
    }

    public static function controlRateable(object $c): bool
    {
        return $c->data_status === DataStatus::VERIFIED && filled($c->requirement_summary) && filled($c->source_reference);
    }

    public function controls(?string $framework, ?string $tenantId): array
    {
        $latest = $tenantId ? DB::table('compliance_control_assessments')->where('tenant_id', $tenantId)->orderByDesc('assessed_at')->get()->unique('control_id')->keyBy('control_id') : collect();

        return DB::table('compliance_controls')->when($framework, fn ($q) => $q->where('framework', strtoupper($framework)))->orderBy('framework')->orderBy('control_code')->get()
            ->map(fn ($c) => ['evidence_types' => json_decode((string) $c->evidence_types, true), 'rateable' => self::controlRateable($c),
                'current_status' => $latest[$c->id]->status ?? 'NOT_ASSESSED'] + (array) $c)->all();
    }

    // ------------------------------------------------------------------ regulatory dictionary

    public function assertReportUsable(string $reportCode): void
    {
        $status = DB::table('regulatory_report_dictionary_lines')->where('report_code', $reportCode)->where('data_status', DataStatus::VERIFIED)->exists()
            ? DataStatus::VERIFIED : DataStatus::PENDING_SOURCE;
        ProductionUseGuard::assertUsable('regulatory_reporting', $status, $reportCode, 'Import the official report form line by line (regulatory_report_dictionary_lines).');
    }

    private function decode(object $p): object
    {
        $p->trigger_events = json_decode((string) $p->trigger_events, true);

        return $p;
    }
}
