<?php

declare(strict_types=1);

namespace App\Application\Underwriting\Workbench;

use App\Application\Documents\Engine\DocumentAccessPolicy;
use App\Application\Underwriting\UnderwritingCaseMachine;
use App\Application\Underwriting\UnderwritingService;
use App\Application\WebExperiences\Money;
use App\Application\WebExperiences\PortalScope;
use App\Models\ApprovalRequest;
use App\Models\Claim;
use App\Models\Document;
use App\Models\Policy;
use App\Models\Proposal;
use App\Models\ProposalDocument;
use App\Models\UnderwritingCase;
use App\Models\UnderwritingDecision;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Q8 underwriting workbench (docs/LAUNCH_SCREEN_GAPS_2026-09-29.md §5, UND-001..020): read models behind the case tabs
 * of UnderwritingCaseResource and the underwriting dashboard. Every section returns the generic shape rendered by
 * resources/views/filament/shared/uw-workbench-section.blade.php:
 *   ['pairs' => [[label, value]], 'tables' => [['title', 'columns' => [key => label], 'rows' => [...], 'status' => [keys]]], 'notes' => [string]]
 * Scope: current tenant, and in a portal the caller's own carrier (PortalScope). Medical content (MEDICAL_RESTRICTED
 * documents, MEDICAL information items, health answers) only for documents.medical.read.
 */
final class UnderwritingWorkbenchQuery
{
    private const MEDICAL_PATTERN = '/medic|health|sant[eé]|illness|maladie|disease|condition|hospital|h[oô]pital|pregnan|grossesse|medication|m[eé]dicament|surgery|chirurg|blood|sang|smok|tabac|alcohol|alcool|diagnos|treatment|traitement/i';

    private static function t(string $key): string
    {
        return __('uw_workbench.f.'.$key);
    }

    public static function mayReadMedical(?User $u): bool
    {
        return $u !== null && (bool) rescue(fn () => $u->hasPermission('documents.medical.read'), false, false);
    }

    private static function fmt(mixed $v): string
    {
        if ($v === null || $v === '' || $v === []) {
            return '—';
        }
        if (is_bool($v)) {
            return $v ? __('uw_workbench.yes') : __('uw_workbench.no');
        }
        if ($v instanceof \DateTimeInterface) {
            return $v->format('Y-m-d H:i');
        }
        if (is_array($v)) {
            return implode(', ', array_map(fn ($k, $x) => (is_int($k) ? '' : $k.': ').(is_scalar($x) || $x === null ? (string) $x : json_encode($x)), array_keys($v), $v));
        }

        return (string) $v;
    }

    /** @param array<string, mixed> $pairs */
    private static function pairs(array $pairs): array
    {
        $out = [];
        foreach ($pairs as $k => $v) {
            $out[] = [self::t($k), self::fmt($v)];
        }

        return $out;
    }

    /** @param list<string> $keys */
    private static function cols(array $keys): array
    {
        return array_combine($keys, array_map(fn ($k) => self::t($k), $keys));
    }

    private static function proposal(UnderwritingCase $c): ?Proposal
    {
        // R4: every case tab (profile, questionnaire, coverage, pricing) reads it; loaded once per request.
        return \App\Application\Identity\Rbac\RequestMemo::remember('uw-proposal:'.$c->getKey(),
            fn () => $c->proposal()->with(['party', 'offer.product', 'offer.quote', 'disclosureResponse'])->first());
    }

    /** UND-006 applicant / risk profile. */
    public static function profile(UnderwritingCase $c, ?User $viewer = null): array
    {
        $p = self::proposal($c);
        $party = $p?->party;
        $identity = (array) ($party?->legal_identity ?? []);
        $profile = (array) ($party?->profile ?? []);
        $risk = (array) ($p?->offer?->quote?->getAttribute('risk_facts') ?? []);

        return [
            'pairs' => self::pairs([
                'applicant' => $party?->display_name, 'party_type' => $party?->type, 'party_status' => $party?->status,
                'identity' => array_intersect_key($identity, array_flip(['id_type', 'id_number', 'niu', 'rccm', 'nationality', 'date_of_birth', 'gender'])),
                'profile' => array_intersect_key($profile, array_flip(['occupation', 'city', 'region', 'address', 'employer', 'industry'])),
                'proposal' => $p?->proposal_number, 'product' => $p?->offer?->product?->name, 'line' => $p?->offer?->quote?->line_code,
                'priority' => $c->priority, 'referral_reasons' => $c->referral_reasons,
            ]),
            'tables' => $risk === [] ? [] : [['title' => self::t('risk_details'), 'columns' => self::cols(['item', 'value']),
                'rows' => array_map(fn ($k, $v) => ['item' => (string) $k, 'value' => ! self::mayReadMedical($viewer) && preg_match(self::MEDICAL_PATTERN, (string) $k) ? __('uw_workbench.medical_withheld') : self::fmt($v)], array_keys($risk), $risk)]],
        ];
    }

    private static function partyPolicies(UnderwritingCase $c): Builder
    {
        $partyId = Proposal::whereKey($c->proposal_id)->value('party_id');

        return Policy::query()->where('tenant_id', $c->tenant_id)->where('party_id', $partyId)
            ->when(PortalScope::carrierId() !== null, fn ($q) => $q->where('carrier_id', PortalScope::carrierId()));
    }

    /** UND-007 policy history of the applicant (own carrier in a portal). */
    public static function policyHistory(UnderwritingCase $c): array
    {
        $rows = self::partyPolicies($c)->with('carrier')->orderByDesc('coverage_starts_at')->limit(50)->get()->map(fn (Policy $p) => [
            'policy_number' => $p->policy_number, 'carrier' => $p->carrier?->cima_code, 'status' => $p->status,
            'coverage_starts_at' => self::fmt($p->coverage_starts_at), 'coverage_ends_at' => self::fmt($p->coverage_ends_at),
            'premium' => Money::format($p->premium_minor, $p->currency),
        ])->all();

        return ['pairs' => self::pairs(['policies_total' => count($rows), 'policies_active' => collect($rows)->where('status', 'ACTIVE')->count()]),
            'tables' => [['title' => self::t('policy_history'), 'columns' => self::cols(['policy_number', 'carrier', 'status', 'coverage_starts_at', 'coverage_ends_at', 'premium']), 'rows' => $rows, 'status' => ['status']]]];
    }

    /** UND-008 claims history of the applicant (claims on its policies, or as claimant; own carrier in a portal). */
    public static function claimsHistory(UnderwritingCase $c): array
    {
        $partyId = Proposal::whereKey($c->proposal_id)->value('party_id');
        $claims = Claim::query()->where('tenant_id', $c->tenant_id)
            ->where(fn ($q) => $q->whereIn('policy_id', self::partyPolicies($c)->select('id'))->orWhere('claimant_party_id', $partyId))
            ->when(PortalScope::carrierId() !== null, fn ($q) => $q->whereIn('policy_id', Policy::where('carrier_id', PortalScope::carrierId())->select('id')))
            ->with('policy')->orderByDesc('loss_occurred_at')->limit(50)->get();
        $rows = $claims->map(fn (Claim $x) => [
            'claim_number' => $x->claim_number, 'policy_number' => $x->policy?->policy_number, 'status' => $x->status,
            'loss_occurred_at' => self::fmt($x->loss_occurred_at), 'estimated_loss' => Money::format($x->estimated_loss_minor, $x->currency),
            'approved_amount' => Money::format($x->approved_amount_minor, $x->currency),
        ])->all();

        return ['pairs' => self::pairs([
            'claims_total' => $claims->count(), 'claims_open' => $claims->whereNotIn('status', ['CLOSED', 'SETTLED', 'REJECTED', 'WITHDRAWN', 'PAID'])->count(),
            'claims_approved_total' => Money::format((int) $claims->sum('approved_amount_minor'), $claims->first()?->currency ?? 'XAF'),
        ]), 'tables' => [['title' => self::t('claims_history'), 'columns' => self::cols(['claim_number', 'policy_number', 'status', 'loss_occurred_at', 'estimated_loss', 'approved_amount']), 'rows' => $rows, 'status' => ['status']]]];
    }

    /** UND-009 questionnaire: the proposal's question snapshot and attested answers. Health answers masked without documents.medical.read. */
    public static function questionnaire(UnderwritingCase $c, ?User $viewer): array
    {
        $p = self::proposal($c);
        $answers = (array) ($p?->disclosureResponse?->answers ?? $p?->disclosures ?? []);
        $fields = [];
        foreach ((array) ($p?->question_snapshot['fields'] ?? []) as $f) {
            if (is_array($f) && isset($f['key'])) {
                $fields[(string) $f['key']] = $f;
            }
        }
        $medical = self::mayReadMedical($viewer);
        $masked = 0;
        $rows = [];
        foreach (array_unique([...array_keys($fields), ...array_map('strval', array_keys($answers))]) as $key) {
            $label = (string) ($fields[$key]['label'] ?? $key);
            $value = $answers[$key] ?? null;
            if (! $medical && (preg_match(self::MEDICAL_PATTERN, $key.' '.$label) || ($fields[$key]['sensitivity'] ?? null) === 'MEDICAL')) {
                $masked++;
                $value = __('uw_workbench.medical_withheld');
            }
            $rows[] = ['question' => $label, 'answer' => self::fmt($value), 'required' => self::fmt((bool) ($fields[$key]['required'] ?? false))];
        }

        return [
            'pairs' => self::pairs(['question_set' => $p?->question_snapshot['source'] ?? $p?->question_set_id, 'attested_at' => $p?->disclosureResponse?->attested_at ?? $p?->attested_at,
                'referral_flags' => $p?->disclosureResponse?->referral_flags]),
            'tables' => [['title' => self::t('questionnaire'), 'columns' => self::cols(['question', 'answer', 'required']), 'rows' => $rows]],
            'notes' => $masked > 0 ? [__('uw_workbench.medical_masked', ['count' => $masked])] : [],
        ];
    }

    /** UND-010 supporting documents: proposal requirement checklist; document visibility by DocumentAccessPolicy (medical needs documents.medical.read). */
    public static function supportingDocuments(UnderwritingCase $c, ?User $viewer): array
    {
        $rows = [];
        $withheld = 0;
        $requirements = ProposalDocument::where('proposal_id', $c->proposal_id)->get();
        // R4: the linked documents in one query (was one per requirement).
        $docs = Document::where('tenant_id', $c->tenant_id)->whereIn('id', $requirements->pluck('document_id')->filter()->unique()->values())->get()->keyBy('id');
        foreach ($requirements as $pd) {
            $doc = $pd->document_id ? $docs->get($pd->document_id) : null;
            if ($doc && ($viewer === null || ! DocumentAccessPolicy::staffMay($viewer, $doc))) {
                $withheld++;

                continue;
            }
            $rows[] = ['requirement' => (string) $pd->requirement_code, 'document' => $doc ? (string) ($doc->title ?: $doc->document_type_code ?: $doc->category) : '—',
                'status' => (string) $pd->status, 'scan' => (string) ($doc?->scan_status ?? '—'), 'verified_at' => self::fmt($pd->verified_at), 'notes' => self::fmt($pd->review_notes)];
        }

        return ['pairs' => self::pairs(['documents_total' => count($rows), 'documents_verified' => collect($rows)->whereIn('status', ['VERIFIED', 'ACCEPTED', 'APPROVED'])->count()]),
            'tables' => [['title' => self::t('supporting_documents'), 'columns' => self::cols(['requirement', 'document', 'status', 'scan', 'verified_at', 'notes']), 'rows' => $rows, 'status' => ['status']]],
            'notes' => $withheld > 0 ? [__('uw_workbench.documents_withheld', ['count' => $withheld])] : []];
    }

    /** UND-011 risk assessment: recommendation, state, referrals raised by rules/disclosures. */
    public static function riskAssessment(UnderwritingCase $c): array
    {
        return [
            'pairs' => self::pairs(['state' => UnderwritingCaseMachine::canonicalState($c), 'recommendation' => $c->recommendation, 'risk_band' => $c->risk_band,
                'risk_score' => $c->risk_score, 'evaluated_at' => $c->evaluated_at, 'engine_evaluation_id' => $c->engine_evaluation_id,
                'rule_set_versions' => $c->rule_set_versions, 'available_events' => UnderwritingCaseMachine::availableEvents($c)]),
            'tables' => [['title' => self::t('referrals'), 'columns' => self::cols(['reason_code', 'severity', 'status', 'due_at', 'resolved_at', 'resolution_notes']), 'status' => ['status'],
                'rows' => $c->referrals()->orderBy('created_at')->get()->map(fn ($r) => ['reason_code' => $r->reason_code, 'severity' => self::fmt($r->severity), 'status' => $r->status,
                    'due_at' => self::fmt($r->due_at), 'resolved_at' => self::fmt($r->resolved_at), 'resolution_notes' => self::fmt($r->resolution_notes)])->all()]],
            'notes' => $c->evaluated_at === null ? [__('uw_workbench.not_evaluated')] : [],
        ];
    }

    /** UND-012 risk score details: every scoring factor with weight, input and contribution. */
    public static function riskScore(UnderwritingCase $c): array
    {
        $factors = (array) ($c->risk_factors ?? []);

        return ['pairs' => self::pairs(['risk_score' => $c->risk_score, 'risk_band' => $c->risk_band, 'factors_fired' => count(array_filter($factors, fn ($f) => ! empty($f['fired'])))]),
            'tables' => [['title' => self::t('score_factors'), 'columns' => self::cols(['factor', 'source', 'weight', 'fired', 'contribution', 'input']),
                'rows' => array_map(fn ($f) => ['factor' => (string) ($f['factor'] ?? ''), 'source' => (string) ($f['source'] ?? ''), 'weight' => (string) ($f['weight'] ?? 0),
                    'fired' => self::fmt((bool) ($f['fired'] ?? false)), 'contribution' => (string) ($f['contribution'] ?? 0), 'input' => self::fmt($f['input'] ?? [])], $factors)]],
            'notes' => $factors === [] ? [__('uw_workbench.not_evaluated')] : []];
    }

    /** UND-013 coverage configuration: offered coverage and any agreed cover terms / conditions. */
    public static function coverage(UnderwritingCase $c): array
    {
        $p = self::proposal($c);
        $cov = (array) ($p?->offer?->coverage_snapshot ?? $p?->terms_snapshot['coverage_snapshot'] ?? []);
        $rows = [];
        foreach ($cov as $k => $v) {
            $rows[] = ['cover' => is_array($v) ? (string) ($v['code'] ?? $v['name'] ?? $k) : (string) $k,
                'detail' => is_array($v) ? self::fmt(array_diff_key($v, ['code' => 1])) : self::fmt($v)];
        }
        $conditions = UnderwritingDecision::where('underwriting_case_id', $c->id)->latest('decided_at')->value('conditions');

        return ['pairs' => self::pairs(['product' => $p?->offer?->product?->name, 'cover_terms' => $p?->cover_terms, 'decision_conditions' => $conditions]),
            'tables' => [['title' => self::t('coverage'), 'columns' => self::cols(['cover', 'detail']), 'rows' => $rows]],
            'notes' => [__('uw_workbench.coverage_changes_note')]];
    }

    /** UND-014 pricing and premium review: offer premium components, calculation breakdown, overrides. */
    public static function pricing(UnderwritingCase $c): array
    {
        $o = self::proposal($c)?->offer;
        $cur = $o?->currency ?? 'XAF';
        $rows = [];
        foreach ((array) ($o?->calculation_breakdown ?? []) as $k => $v) {
            $rows[] = ['component' => is_array($v) ? (string) ($v['code'] ?? $v['label'] ?? $k) : (string) $k,
                'amount' => is_array($v) ? (isset($v['amount_minor']) ? Money::format((int) $v['amount_minor'], $cur) : self::fmt($v)) : (is_int($v) && str_ends_with((string) $k, '_minor') ? Money::format($v, $cur) : self::fmt($v))];
        }

        return ['pairs' => self::pairs([
            'premium' => $o ? Money::format($o->premium_minor, $cur) : null, 'tax' => $o ? Money::format($o->tax_minor, $cur) : null, 'fees' => $o ? Money::format($o->fee_minor, $cur) : null,
            'total' => $o ? Money::format($o->total_minor, $cur) : null,
            'original_premium' => $o?->original_premium_minor !== null && $o?->original_premium_minor !== $o?->premium_minor ? Money::format($o->original_premium_minor, $cur) : null,
            'premium_override' => $o?->premium_override_id, 'tariff_version' => $o?->tariff_version_id, 'valid_until' => $o?->valid_until,
        ]), 'tables' => [['title' => self::t('premium_breakdown'), 'columns' => self::cols(['component', 'amount']), 'rows' => $rows]]];
    }

    /** @return list<array<string, mixed>> */
    private static function items(UnderwritingCase $c): array
    {
        return array_values(array_filter((array) (Proposal::whereKey($c->proposal_id)->first()?->information_request['items'] ?? []), 'is_array'));
    }

    /** UND-015 additional information request: the current request and its items (MEDICAL items masked without permission). */
    public static function informationRequest(UnderwritingCase $c, ?User $viewer): array
    {
        $req = (array) (Proposal::whereKey($c->proposal_id)->first()?->information_request ?? []);
        $medical = self::mayReadMedical($viewer);

        return ['pairs' => self::pairs(['requested_at' => $req['requested_at'] ?? null, 'requested_by' => isset($req['requested_by']) ? User::whereKey($req['requested_by'])->value('full_name') : null,
            'responded_at' => $req['responded_at'] ?? null, 'message' => $req['message'] ?? null]),
            'tables' => [['title' => self::t('requested_items'), 'columns' => self::cols(['code', 'kind', 'description', 'mandatory']),
                'rows' => array_map(fn ($i) => ['code' => (string) ($i['code'] ?? ''), 'kind' => (string) ($i['kind'] ?? ''),
                    'description' => ($i['kind'] ?? null) === 'MEDICAL' && ! $medical ? __('uw_workbench.medical_withheld') : (string) ($i['description'] ?? ''),
                    'mandatory' => self::fmt((bool) ($i['mandatory'] ?? true))], self::items($c))]],
            'notes' => $req === [] ? [__('uw_workbench.no_information_request')] : []];
    }

    /** UND-016 inspection / medical requirements: INSPECTION and MEDICAL items (MEDICAL only with documents.medical.read). */
    public static function requirements(UnderwritingCase $c, ?User $viewer): array
    {
        $medical = self::mayReadMedical($viewer);
        $items = array_filter(self::items($c), fn ($i) => in_array($i['kind'] ?? null, ['INSPECTION', 'MEDICAL'], true));
        $hidden = count(array_filter($items, fn ($i) => $i['kind'] === 'MEDICAL')) * (int) ! $medical;
        $visible = array_filter($items, fn ($i) => $medical || $i['kind'] !== 'MEDICAL');

        return ['pairs' => self::pairs(['inspections' => count(array_filter($items, fn ($i) => $i['kind'] === 'INSPECTION')), 'medical' => $medical ? count($items) - count(array_filter($items, fn ($i) => $i['kind'] === 'INSPECTION')) : __('uw_workbench.restricted')]),
            'tables' => [['title' => self::t('requirements'), 'columns' => self::cols(['code', 'kind', 'description', 'mandatory']),
                'rows' => array_values(array_map(fn ($i) => ['code' => (string) $i['code'], 'kind' => (string) $i['kind'], 'description' => (string) ($i['description'] ?? ''), 'mandatory' => self::fmt((bool) ($i['mandatory'] ?? true))], $visible))]],
            'notes' => array_values(array_filter([$hidden > 0 ? __('uw_workbench.medical_masked', ['count' => $hidden]) : null, __('uw_workbench.requirements_note')]))];
    }

    /** UND-019 supervisor approval: referral escalations, maker-checker approval requests on the case/proposal and the decision trail. */
    public static function supervisorApproval(UnderwritingCase $c): array
    {
        $approvals = ApprovalRequest::query()->where('tenant_id', $c->tenant_id)
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('subject_type', 'underwriting_case')->where('subject_id', $c->id))
                ->orWhere(fn ($q) => $q->where('subject_type', 'proposal')->where('subject_id', $c->proposal_id)))
            ->with(['requester', 'decider'])->orderByDesc('created_at')->limit(30)->get();
        $names = User::whereIn('id', UnderwritingDecision::where('underwriting_case_id', $c->id)->pluck('decided_by'))->pluck('full_name', 'id');

        return ['pairs' => self::pairs(['open_referrals' => $c->referrals()->where('status', 'OPEN')->count(), 'pending_approvals' => $approvals->where('status', 'PENDING')->count(),
            'recommendation' => $c->recommendation, 'outcome' => $c->outcome]),
            'tables' => [
                ['title' => self::t('approval_requests'), 'columns' => self::cols(['action_code', 'status', 'requested_by', 'decided_by', 'decided_at', 'reason']), 'status' => ['status'],
                    'rows' => $approvals->map(fn ($a) => ['action_code' => $a->action_code, 'status' => $a->status, 'requested_by' => (string) ($a->requester?->full_name ?? '—'),
                        'decided_by' => (string) ($a->decider?->full_name ?? '—'), 'decided_at' => self::fmt($a->decided_at), 'reason' => self::fmt($a->reason)])->all()],
                ['title' => self::t('decisions'), 'columns' => self::cols(['outcome', 'reason_code', 'system_recommendation', 'agrees', 'decided_by', 'decided_at']),
                    'rows' => UnderwritingDecision::where('underwriting_case_id', $c->id)->orderBy('decided_at')->get()->map(fn (UnderwritingDecision $d) => [
                        'outcome' => (string) ($d->outcome ?? $d->decision), 'reason_code' => (string) $d->reason_code, 'system_recommendation' => self::fmt($d->system_recommendation),
                        'agrees' => $d->system_recommendation ? self::fmt(UnderwritingService::agrees((string) $d->system_recommendation, (string) ($d->outcome ?? $d->decision))) : '—',
                        'decided_by' => (string) ($names[$d->decided_by] ?? '—'), 'decided_at' => self::fmt($d->decided_at)])->all()],
            ]];
    }

    /** Tenant + own-carrier case scope for the dashboard. */
    public static function scope(string $tenantId): Builder
    {
        return PortalScope::narrowToCarrier(UnderwritingCase::query()->where('tenant_id', $tenantId));
    }

    /** UND-001 dashboard + UND-004 my assigned cases. */
    public static function dashboard(string $tenantId, ?User $me): array
    {
        $open = ['QUEUED', 'IN_REVIEW', 'AWAITING_INFORMATION', 'DECISION_PENDING'];
        $byStatus = self::scope($tenantId)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->all();
        $overdue = self::scope($tenantId)->whereIn('status', $open)->whereNotNull('decision_due_at')->where('decision_due_at', '<', now())->count();
        $openReferrals = \App\Models\UnderwritingReferralTask::whereIn('underwriting_case_id', self::scope($tenantId)->select('id'))->where('status', 'OPEN')->count();
        $unassigned = self::scope($tenantId)->whereIn('status', $open)->whereNull('assigned_to')->count();
        $mine = $me ? self::scope($tenantId)->whereIn('status', $open)->where('assigned_to', $me->id)->with(['proposal.party'])->orderBy('decision_due_at')->limit(50)->get() : collect();

        return [
            'kpis' => [
                ['label' => __('uw_workbench.kpi.open'), 'value' => array_sum(array_intersect_key($byStatus, array_flip($open)))],
                ['label' => __('uw_workbench.kpi.unassigned'), 'value' => $unassigned],
                ['label' => __('uw_workbench.kpi.mine'), 'value' => $mine->count()],
                ['label' => __('uw_workbench.kpi.awaiting_information'), 'value' => (int) ($byStatus['AWAITING_INFORMATION'] ?? 0)],
                ['label' => __('uw_workbench.kpi.decision_pending'), 'value' => (int) ($byStatus['DECISION_PENDING'] ?? 0)],
                ['label' => __('uw_workbench.kpi.open_referrals'), 'value' => $openReferrals],
                ['label' => __('uw_workbench.kpi.overdue'), 'value' => $overdue],
            ],
            'status' => ['pairs' => array_map(fn ($s, $n) => [$s, (string) $n], array_keys($byStatus), $byStatus)],
            'mine' => ['tables' => [['title' => __('uw_workbench.assigned_cases'), 'columns' => self::cols(['proposal', 'applicant', 'state', 'priority', 'risk_band', 'decision_due_at']),
                'status' => ['state'], 'links' => 'url',
                'rows' => $mine->map(fn (UnderwritingCase $c) => ['proposal' => (string) ($c->proposal?->proposal_number ?? '—'), 'applicant' => (string) ($c->proposal?->party?->display_name ?? '—'),
                    'state' => UnderwritingCaseMachine::canonicalState($c), 'priority' => (string) ($c->priority ?? '—'), 'risk_band' => (string) ($c->risk_band ?? '—'),
                    'decision_due_at' => self::fmt($c->decision_due_at), 'id' => $c->id])->all()]]],
        ];
    }

    /** UND-020 performance and SLA over the last N days. */
    public static function performance(string $tenantId, int $days = 30): array
    {
        $since = now()->subDays($days);
        $decisions = UnderwritingDecision::query()->whereIn('underwriting_case_id', self::scope($tenantId)->select('id'))->where('decided_at', '>=', $since)
            ->with('underwritingCase')->get();
        $hours = fn (UnderwritingDecision $d) => $d->underwritingCase?->created_at && $d->decided_at ? $d->underwritingCase->created_at->diffInMinutes($d->decided_at) / 60 : null;
        $withinSla = $decisions->filter(fn ($d) => $d->underwritingCase?->decision_due_at !== null);
        $met = $withinSla->filter(fn ($d) => $d->decided_at <= $d->underwritingCase->decision_due_at)->count();
        $names = User::whereIn('id', $decisions->pluck('decided_by')->filter())->pluck('full_name', 'id');
        $rows = $decisions->groupBy('decided_by')->map(function ($g, $uid) use ($names, $hours) {
            $agree = $g->filter(fn ($d) => $d->system_recommendation);

            return ['underwriter' => (string) ($names[$uid] ?? '—'), 'decisions' => (string) $g->count(),
                'avg_hours' => number_format((float) $g->map($hours)->filter(fn ($h) => $h !== null)->avg(), 1),
                'approved' => (string) $g->filter(fn ($d) => in_array($d->outcome ?? $d->decision, ['APPROVED', 'CONDITIONAL'], true))->count(),
                'declined' => (string) $g->where('decision', 'DECLINED')->count(),
                'agreement' => $agree->isEmpty() ? '—' : round(100 * $agree->filter(fn ($d) => UnderwritingService::agrees((string) $d->system_recommendation, (string) ($d->outcome ?? $d->decision)))->count() / $agree->count()).'%'];
        })->values()->all();

        return ['pairs' => [
            [__('uw_workbench.perf.period'), __('uw_workbench.perf.last_days', ['days' => $days])],
            [__('uw_workbench.perf.decisions'), (string) $decisions->count()],
            [__('uw_workbench.perf.avg_turnaround'), $decisions->isEmpty() ? '—' : number_format((float) $decisions->map($hours)->filter(fn ($h) => $h !== null)->avg(), 1).' h'],
            [__('uw_workbench.perf.sla_met'), $withinSla->isEmpty() ? '—' : round(100 * $met / $withinSla->count()).'% ('.$met.'/'.$withinSla->count().')'],
            [__('uw_workbench.perf.overdue_open'), (string) self::scope($tenantId)->whereIn('status', ['QUEUED', 'IN_REVIEW', 'AWAITING_INFORMATION', 'DECISION_PENDING'])->where('decision_due_at', '<', now())->count()],
        ], 'tables' => [['title' => __('uw_workbench.perf.by_underwriter'), 'columns' => self::cols(['underwriter', 'decisions', 'avg_hours', 'approved', 'declined', 'agreement']), 'rows' => $rows]]];
    }
}
