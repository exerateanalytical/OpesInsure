<?php

declare(strict_types=1);

namespace App\Application\Claims\Adjusters;

use App\Application\Audit\AuditWriter;
use App\Application\Cases\CaseService;
use App\Application\Cases\Models\WorkCase;
use App\Application\Events\OutboxWriter;
use App\Application\Providers\ProviderNetworkService;
use App\Application\Providers\ProviderRegistry;
use App\Interfaces\Http\Errors\ApiProblemException;
use App\Models\Claim;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * REQ-CLM-009 / WF-053 — assigns claim experts / loss adjusters from the Batch 13A provider master and runs their
 * lifecycle. Rows live in claim_assignments (assignment_type = EXPERT); history in claim_assignment_events;
 * SLA on a CLAIM_EXPERT_ASSIGNMENT case. Adjuster-side steps are only allowed to users linked to the provider
 * (the user's party is the provider's party, or has an ACTIVE EMPLOYED_BY relationship to it).
 */
final class ExpertAssignmentService
{
    /** Claim statuses on which no new expert may be appointed. */
    private const CLAIM_CLOSED_STATUSES = ['DRAFT', 'CLOSED', 'SETTLED', 'REJECTED', 'WITHDRAWN', 'CANCELLED'];

    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly ProviderNetworkService $networks,
        private readonly CaseService $cases,
        private readonly AuditWriter $audit,
        private readonly OutboxWriter $outbox,
    ) {}

    /** @param array{provider_id: string, network_id: string, fee_service_id: string, instructions?: ?string} $d */
    public function assign(Claim $claim, array $d, User $actor): object
    {
        if (in_array($claim->status, self::CLAIM_CLOSED_STATUSES, true)) {
            throw new ApiProblemException('CLAIM_NOT_ASSIGNABLE', 409, "An expert cannot be appointed on a {$claim->status} claim.");
        }
        $tenantId = $claim->tenant_id;
        $p = $this->providers->find($d['provider_id']);
        if (! in_array($p->category, ExpertAssignmentLifecycle::PROVIDER_CATEGORIES, true)) {
            throw new ApiProblemException('PROVIDER_NOT_EXPERT', 422, "A {$p->category} provider cannot be appointed as claim expert.");
        }
        if ($p->credentialing_status !== 'ACTIVE') {
            throw new ApiProblemException('PROVIDER_NOT_ACTIVE', 409, "Only ACTIVE experts can be appointed (provider is {$p->credentialing_status}).");
        }
        $this->networks->network($tenantId, $d['network_id']);
        $today = CarbonImmutable::now()->toDateString();
        if (! $this->networks->isInNetwork($tenantId, $d['network_id'], $p->id, $today)) {
            throw new ApiProblemException('PROVIDER_NOT_IN_NETWORK', 409, 'The expert is not an active member of this network.');
        }
        $contract = DB::table('provider_contracts')->where('tenant_id', $tenantId)->where('provider_network_id', $d['network_id'])
            ->where('provider_profile_id', $p->id)->where('status', 'ACTIVE')->where('effective_from', '<=', $today)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $today))->orderByDesc('effective_from')->first();
        if (! $contract) {
            throw new ApiProblemException('EXPERT_CONTRACT_MISSING', 409, 'The expert has no active contract in this network.');
        }
        $price = $this->networks->priceFor($tenantId, $contract->id, $d['fee_service_id'], $today);
        if (! $price) {
            throw new ApiProblemException('EXPERT_TARIFF_MISSING', 409, 'No approved tariff line prices this expert service under the contract.');
        }

        return DB::transaction(function () use ($claim, $d, $actor, $p, $contract, $price, $tenantId) {
            Claim::whereKey($claim->id)->lockForUpdate()->firstOrFail();
            $open = DB::table('claim_assignments')->where('claim_id', $claim->id)->where('assignment_type', 'EXPERT')
                ->where('provider_profile_id', $p->id)->whereNotIn('status', ExpertAssignmentLifecycle::TERMINAL)->exists();
            if ($open) {
                throw new ApiProblemException('EXPERT_ALREADY_ASSIGNED', 409, 'This expert already has an open assignment on the claim.');
            }
            $id = (string) Str::uuid();
            $case = $this->cases->open($tenantId, ExpertAssignmentLifecycle::CASE_TYPE, [
                'title' => "Expert assignment {$claim->claim_number} — {$p->name}", 'subject_type' => 'claim', 'subject_id' => $claim->id,
                'source_type' => 'claim_assignment', 'source_id' => $id, 'owner_user_id' => $claim->assigned_to ?? $actor->id,
                'idempotency_key' => 'claim-expert-'.$id,
            ], $actor);
            DB::table('claim_assignments')->insert([
                'id' => $id, 'claim_id' => $claim->id, 'assignee_id' => null, 'assigned_by' => $actor->id, 'reason_code' => 'EXPERT_APPOINTMENT',
                'assigned_at' => now(), 'assignment_type' => 'EXPERT', 'tenant_id' => $tenantId, 'provider_profile_id' => $p->id,
                'provider_network_id' => $d['network_id'], 'provider_contract_id' => $contract->id, 'status' => 'ASSIGNMENT_PENDING',
                'fee_service_id' => $d['fee_service_id'], 'fee_tariff_line_id' => $price->id, 'fee_amount_minor' => (int) $price->contracted_price_minor,
                'fee_currency' => $price->currency, 'case_id' => $case->id, 'instructions' => $d['instructions'] ?? null, 'version' => 1, 'updated_at' => now(),
            ]);
            $this->event($id, 'assign', null, 'ASSIGNMENT_PENDING', $actor, 'INSURER', null, ['fee_amount_minor' => (int) $price->contracted_price_minor]);
            $this->publish('assign', $claim->id, $id, $p->id, 'ASSIGNMENT_PENDING', null);

            return $this->find($tenantId, $id);
        });
    }

    public function accept(string $tenantId, string $id, User $actor): object
    {
        return $this->move($tenantId, $id, 'accept', $actor, null, fn () => ['accepted_at' => now()]);
    }

    public function decline(string $tenantId, string $id, string $reason, User $actor): object
    {
        return $this->move($tenantId, $id, 'decline', $actor, $reason, fn () => ['decline_reason' => $reason]);
    }

    public function scheduleInspection(string $tenantId, string $id, string $at, ?string $location, User $actor): object
    {
        $when = CarbonImmutable::parse($at);

        return $this->move($tenantId, $id, 'schedule_inspection', $actor, null, fn () => ['inspection_scheduled_for' => $when, 'inspection_location' => $location],
            ['scheduled_for' => $when->toIso8601String(), 'location' => $location]);
    }

    public function recordInspection(string $tenantId, string $id, ?string $notes, ?string $inspectedAt, User $actor): object
    {
        $when = CarbonImmutable::parse($inspectedAt ?? now());
        if ($when->isFuture()) {
            throw new ApiProblemException('INSPECTION_IN_FUTURE', 422, 'An inspection cannot be recorded in the future.');
        }

        return $this->move($tenantId, $id, 'record_inspection', $actor, null, fn () => ['inspected_at' => $when, 'inspection_notes' => $notes]);
    }

    /** @param array{summary: string, assessed_loss_minor: int, document_id?: ?string} $d */
    public function submitReport(string $tenantId, string $id, array $d, User $actor): object
    {
        if (! empty($d['document_id']) && ! DB::table('documents')->where('id', $d['document_id'])->where(fn ($q) => $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))->exists()) {
            throw new ApiProblemException('DOCUMENT_NOT_FOUND', 422, 'The report document was not found.');
        }

        return $this->move($tenantId, $id, 'submit_report', $actor, null, fn () => [
            'report_summary' => $d['summary'], 'assessed_loss_minor' => (int) $d['assessed_loss_minor'],
            'report_document_id' => $d['document_id'] ?? null, 'report_submitted_at' => now(), 'reviewed_by' => null, 'reviewed_at' => null,
        ], ['assessed_loss_minor' => (int) $d['assessed_loss_minor']]);
    }

    public function acceptReport(string $tenantId, string $id, ?string $notes, User $actor): object
    {
        return $this->move($tenantId, $id, 'accept_report', $actor, $notes, fn () => ['reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_notes' => $notes]);
    }

    public function returnReport(string $tenantId, string $id, string $reason, User $actor): object
    {
        return $this->move($tenantId, $id, 'return_report', $actor, $reason,
            fn ($a) => ['reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_notes' => $reason, 'return_count' => $a->return_count + 1]);
    }

    public function cancel(string $tenantId, string $id, string $reason, User $actor): object
    {
        return $this->move($tenantId, $id, 'cancel', $actor, $reason, fn () => []);
    }

    // ----------------------------------------------------------------- reads

    public function find(string $tenantId, string $id): object
    {
        $a = DB::table('claim_assignments as a')->join('provider_profiles as p', 'p.id', '=', 'a.provider_profile_id')
            ->join('parties', 'parties.id', '=', 'p.party_id')
            ->where('a.tenant_id', $tenantId)->where('a.assignment_type', 'EXPERT')->where('a.id', $id)
            ->select('a.*', 'parties.display_name as provider_name', 'p.category as provider_category', 'p.provider_type_code')->first()
            ?? throw new ApiProblemException('ASSIGNMENT_NOT_FOUND', 404, 'Expert assignment not found.');
        $a->available_events = $this->availableEvents($a->status);
        $a->sla = $a->case_id ? DB::table('sla_clocks')->where('case_id', $a->case_id)
            ->select('metric', 'due_at', 'stopped_at', 'breached_at')->orderBy('started_at')->get()->all() : [];

        return $a;
    }

    /** Handler and expert assignments of a claim, oldest first. */
    public function forClaim(Claim $claim): array
    {
        return DB::table('claim_assignments as a')->leftJoin('provider_profiles as p', 'p.id', '=', 'a.provider_profile_id')
            ->leftJoin('parties', 'parties.id', '=', 'p.party_id')
            ->where('a.claim_id', $claim->id)->orderBy('a.assigned_at')
            ->select('a.*', 'parties.display_name as provider_name')->get()->all();
    }

    public function history(string $id): array
    {
        return DB::table('claim_assignment_events')->where('claim_assignment_id', $id)->orderBy('occurred_at')->orderBy('seq')->get()->all();
    }

    /** Provider profiles (ADJUSTER / EXPERT) the user acts for. @return list<string> */
    public function providerIdsFor(User $user): array
    {
        if (! $user->party_id) {
            return [];
        }
        $employers = DB::table('party_relationships')->where('from_party_id', $user->party_id)->where('type', 'EMPLOYED_BY')->where('status', 'ACTIVE')
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', now()->toDateString()))->pluck('to_party_id')->all();

        return DB::table('provider_profiles')->whereIn('party_id', array_merge([$user->party_id], $employers))
            ->whereIn('category', ExpertAssignmentLifecycle::PROVIDER_CATEGORIES)->pluck('id')->all();
    }

    /** The adjuster's own assignments only, with a minimal claim view. */
    public function forAdjuster(string $tenantId, User $user, ?string $status): array
    {
        return DB::table('claim_assignments as a')->join('claims as c', 'c.id', '=', 'a.claim_id')
            ->where('a.tenant_id', $tenantId)->where('a.assignment_type', 'EXPERT')->whereIn('a.provider_profile_id', $this->providerIdsFor($user) ?: ['00000000-0000-0000-0000-000000000000'])
            ->when($status, fn ($q, $s) => $q->where('a.status', $s))->orderByDesc('a.assigned_at')
            ->select('a.id', 'a.claim_id', 'a.status', 'a.assigned_at', 'a.accepted_at', 'a.inspection_scheduled_for', 'a.inspection_location',
                'a.report_submitted_at', 'a.fee_amount_minor', 'a.fee_currency', 'a.instructions', 'a.review_notes', 'a.return_count',
                'c.claim_number', 'c.loss_occurred_at', 'c.loss_location')->limit(500)->get()->all();
    }

    /** One of the adjuster's own assignments (404 otherwise — existence is not leaked). */
    public function forAdjusterOne(string $tenantId, User $user, string $id): object
    {
        $a = $this->find($tenantId, $id);
        if (! in_array($a->provider_profile_id, $this->providerIdsFor($user), true)) {
            throw new ApiProblemException('ASSIGNMENT_NOT_FOUND', 404, 'Expert assignment not found.');
        }
        $c = DB::table('claims')->where('id', $a->claim_id)->first(['claim_number', 'loss_occurred_at', 'loss_location', 'loss_details']);
        $details = json_decode((string) $c->loss_details, true) ?: [];

        return (object) [
            'id' => $a->id, 'status' => $a->status, 'available_events' => $a->available_events, 'assigned_at' => $a->assigned_at,
            'instructions' => $a->instructions, 'fee_amount_minor' => $a->fee_amount_minor, 'fee_currency' => $a->fee_currency,
            'inspection_scheduled_for' => $a->inspection_scheduled_for, 'inspection_location' => $a->inspection_location, 'inspected_at' => $a->inspected_at,
            'report_summary' => $a->report_summary, 'assessed_loss_minor' => $a->assessed_loss_minor, 'report_submitted_at' => $a->report_submitted_at,
            'review_notes' => $a->review_notes, 'return_count' => $a->return_count, 'sla' => $a->sla,
            'claim' => ['id' => $a->claim_id, 'claim_number' => $c->claim_number, 'loss_occurred_at' => $c->loss_occurred_at,
                'loss_location' => $c->loss_location, 'description' => $details['description'] ?? null],
        ];
    }

    public function assertAdjusterOwns(string $tenantId, User $user, string $id): void
    {
        $this->forAdjusterOne($tenantId, $user, $id);
    }

    // ----------------------------------------------------------------- internals

    /** @return list<string> */
    private function availableEvents(string $status): array
    {
        return array_values(array_filter(array_keys(ExpertAssignmentLifecycle::TRANSITIONS), fn ($e) => ExpertAssignmentLifecycle::target($status, $e) !== null));
    }

    private function move(string $tenantId, string $id, string $event, User $actor, ?string $reason, callable $changes, array $payload = []): object
    {
        return DB::transaction(function () use ($tenantId, $id, $event, $actor, $reason, $changes, $payload) {
            $a = DB::table('claim_assignments')->where('id', $id)->where('tenant_id', $tenantId)->where('assignment_type', 'EXPERT')->lockForUpdate()->first()
                ?? throw new ApiProblemException('ASSIGNMENT_NOT_FOUND', 404, 'Expert assignment not found.');
            $to = ExpertAssignmentLifecycle::target($a->status, $event);
            if ($to === null) {
                throw new ApiProblemException('ASSIGNMENT_TRANSITION_INVALID', 409, "Cannot {$event} an assignment that is {$a->status}.", [],
                    ['from' => $a->status, 'event' => $event, 'allowed' => $this->availableEvents($a->status)]);
            }
            $side = ExpertAssignmentLifecycle::TRANSITIONS[$event][2];
            if (in_array($event, ['decline', 'return_report', 'cancel'], true) && trim((string) $reason) === '') {
                throw new ApiProblemException('REASON_REQUIRED', 422, "A reason is required to {$event}.");
            }
            if ($side === 'ADJUSTER' && ! in_array($a->provider_profile_id, $this->providerIdsFor($actor), true)) {
                throw new ApiProblemException('NOT_ASSIGNED_EXPERT', 403, 'Only the appointed expert can perform this step.');
            }
            if ($side === 'INSURER' && in_array($a->provider_profile_id, $this->providerIdsFor($actor), true)) {
                throw new ApiProblemException('MAKER_CHECKER', 403, 'The appointed expert cannot review or cancel their own assignment.');
            }
            DB::table('claim_assignments')->where('id', $id)->update(array_merge($changes($a), [
                'status' => $to, 'version' => $a->version + 1, 'updated_at' => now(),
                'released_at' => in_array($to, ExpertAssignmentLifecycle::TERMINAL, true) ? now() : null,
            ]));
            if ($to !== $a->status && $a->case_id) {
                $case = WorkCase::withoutGlobalScopes()->find($a->case_id);
                if ($case && $case->status === $a->status) {
                    $this->cases->transition($case, $event, $actor, $reason, $payload);
                }
            }
            $this->event($id, $event, $a->status, $to, $actor, $side, $reason, $payload);
            $this->publish($event, $a->claim_id, $id, $a->provider_profile_id, $to, $a->status);

            return $this->find($tenantId, $id);
        });
    }

    private function event(string $id, string $event, ?string $from, string $to, User $actor, string $side, ?string $reason, array $payload): void
    {
        DB::table('claim_assignment_events')->insert([
            'id' => (string) Str::uuid(), 'claim_assignment_id' => $id, 'event' => $event, 'from_status' => $from, 'to_status' => $to,
            'actor_id' => $actor->id, 'actor_side' => $side, 'reason' => $reason, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'occurred_at' => now(),
        ]);
    }

    private function publish(string $event, string $claimId, string $assignmentId, string $providerId, string $to, ?string $from): void
    {
        $name = ExpertAssignmentLifecycle::DOMAIN_EVENTS[$event];
        $data = ['claim_id' => $claimId, 'assignment_id' => $assignmentId, 'provider_id' => $providerId, 'from' => $from, 'to' => $to];
        $this->audit->record($name, 'claim', $claimId, $data);
        $this->outbox->record($name, 'claim', $claimId, $data);
    }
}
