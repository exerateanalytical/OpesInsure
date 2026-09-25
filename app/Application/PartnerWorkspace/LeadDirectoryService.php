<?php

declare(strict_types=1);

namespace App\Application\PartnerWorkspace;

use App\Application\Audit\AuditWriter;
use App\Application\Cases\Models\DiaryEntry;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * REQ-CRM-001 — tenant / broker lead directory, assignment rules and lead
 * activities over the canonical partner_leads table (no second leads table).
 *
 * Scope: a broker user (PartnerWorkspaceScope::broker) only ever sees and
 * assigns inside its own BROKER partner; other tenant staff with
 * crm.leads.* see the tenant directory. Agents keep the mobile endpoints
 * (AgentLeadService), which share the pipeline and the activity log.
 *
 * Assignment rules (WF-005..007): EXPLICIT (partner and/or user given),
 * BROKER_SELF (a broker's lead stays in its firm, assigned to the creator),
 * LEAST_LOADED (auto: ACTIVE AGENT/BROKER partner with the fewest open leads),
 * or UNASSIGNED. Every change is kept in partner_lead_assignments.
 *
 * Activities/follow-ups reuse the case engine diary (diary_entries with
 * subject_type = partner_lead): follow-ups appear in "My Work" and fire
 * diary.follow_up_due from the SLA tick with no new plumbing.
 */
final class LeadDirectoryService
{
    public const SUBJECT = 'partner_lead';

    public const ACTIVITY_TYPES = ['NOTE', 'CALL', 'MEETING', 'FOLLOW_UP'];

    public function __construct(private readonly PartnerWorkspaceScope $scope, private readonly AuditWriter $audit) {}

    /** @param array{status?: ?string, partner_id?: ?string, assigned_user_id?: ?string, q?: ?string} $f */
    public function list(User $user, string $tenantId, array $f = []): Collection
    {
        $q = $this->query($user, $tenantId);
        foreach (['status', 'partner_id', 'assigned_user_id'] as $k) {
            if (! empty($f[$k])) {
                $q->where($k, $f[$k]);
            }
        }
        if (! empty($f['q'])) {
            $term = '%'.mb_strtolower($f['q']).'%';
            $q->where(fn ($w) => $w->whereRaw('lower(full_name) like ?', [$term])->orWhere('phone_e164', 'like', $term));
        }

        return $q->orderByDesc('created_at')->limit(200)->get();
    }

    /** Pipeline counts per status for the caller's scope. */
    public function pipeline(User $user, string $tenantId): array
    {
        $counts = $this->query($user, $tenantId)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return collect(LeadPipeline::STATUSES)->mapWithKeys(fn ($s) => [$s => (int) ($counts[$s] ?? 0)])->all();
    }

    public function find(string $id, User $user, string $tenantId): object
    {
        $lead = Str::isUuid($id) ? $this->query($user, $tenantId)->where('id', $id)->first() : null;
        abort_unless($lead, 404);

        return $lead;
    }

    public function create(array $d, User $user, string $tenantId): object
    {
        $broker = $this->scope->broker($user);
        [$partnerId, $userId, $rule] = match (true) {
            $broker !== null => [$broker->id, $d['assigned_user_id'] ?? $user->id, 'BROKER_SELF'],
            ! empty($d['partner_id']) || ! empty($d['assigned_user_id']) => [$d['partner_id'] ?? null, $d['assigned_user_id'] ?? null, 'EXPLICIT'],
            ! empty($d['auto_assign']) => [$this->leastLoadedPartner($tenantId)?->id, null, 'LEAST_LOADED'],
            default => [null, null, 'UNASSIGNED'],
        };
        if ($partnerId) {
            $this->activePartner($partnerId, $tenantId);
        }
        if ($userId) {
            $this->assertMember($userId, $tenantId);
        }

        return DB::transaction(function () use ($d, $user, $tenantId, $partnerId, $userId, $rule) {
            $id = (string) Str::uuid();
            DB::table('partner_leads')->insert([
                'id' => $id, 'tenant_id' => $tenantId, 'partner_id' => $partnerId, 'assigned_user_id' => $userId,
                'full_name' => $d['full_name'], 'phone_e164' => $d['phone_e164'], 'city' => $d['city'] ?? null,
                'product_interest' => isset($d['product_interest']) ? strtoupper($d['product_interest']) : null, 'notes' => $d['notes'] ?? null,
                'source' => isset($d['source']) ? strtoupper($d['source']) : null, 'status' => 'NEW', 'status_changed_at' => now(),
                'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->history($id, null, $partnerId, null, $userId, $rule, null, $user);
            $this->audit->record('crm.lead.created', self::SUBJECT, $id, ['partner_id' => $partnerId, 'assigned_user_id' => $userId, 'rule' => $rule]);

            return DB::table('partner_leads')->find($id);
        });
    }

    public function assign(string $id, array $d, User $user, string $tenantId): object
    {
        $lead = $this->find($id, $user, $tenantId);
        if (! LeadPipeline::isOpen($lead->status)) {
            throw ValidationException::withMessages(['status' => ['Only open leads can be reassigned.']]);
        }
        $broker = $this->scope->broker($user);
        $partnerId = array_key_exists('partner_id', $d) ? $d['partner_id'] : $lead->partner_id;
        if ($broker && $partnerId !== $broker->id) {
            throw ValidationException::withMessages(['partner_id' => ['A broker can only assign leads inside its own firm.']]);
        }
        if ($partnerId) {
            $this->activePartner($partnerId, $tenantId);
        }
        $userId = array_key_exists('assigned_user_id', $d) ? $d['assigned_user_id'] : ($partnerId === $lead->partner_id ? $lead->assigned_user_id : null);
        if ($userId) {
            $this->assertMember($userId, $tenantId);
        }

        return DB::transaction(function () use ($lead, $partnerId, $userId, $d, $user) {
            DB::table('partner_leads')->where('id', $lead->id)->update(['partner_id' => $partnerId, 'assigned_user_id' => $userId, 'updated_at' => now()]);
            $this->history($lead->id, $lead->partner_id, $partnerId, $lead->assigned_user_id, $userId, 'EXPLICIT', $d['reason'] ?? null, $user);
            $this->audit->record('crm.lead.assigned', self::SUBJECT, $lead->id, ['from_partner_id' => $lead->partner_id, 'to_partner_id' => $partnerId, 'to_user_id' => $userId], $d['reason'] ?? null);

            return DB::table('partner_leads')->find($lead->id);
        });
    }

    public function transition(string $id, string $to, ?string $lostReason, User $user, string $tenantId): object
    {
        $lead = $this->find($id, $user, $tenantId);
        LeadPipeline::assert($lead->status, $to);
        if ($to === 'LOST' && blank($lostReason)) {
            throw ValidationException::withMessages(['lost_reason' => ['Give a reason when marking a lead as lost.']]);
        }
        DB::table('partner_leads')->where('id', $lead->id)->update(['status' => $to, 'lost_reason' => $to === 'LOST' ? $lostReason : null, 'status_changed_at' => now(), 'updated_at' => now()]);
        $this->audit->record('crm.lead.status_changed', self::SUBJECT, $lead->id, ['from' => $lead->status, 'to' => $to], $lostReason);

        return DB::table('partner_leads')->find($lead->id);
    }

    /** @return Collection<int, object> */
    public function assignments(object $lead): Collection
    {
        return DB::table('partner_lead_assignments')->where('lead_id', $lead->id)->orderBy('occurred_at')->get();
    }

    /** @return Collection<int, DiaryEntry> */
    public function activities(object $lead): Collection
    {
        return DiaryEntry::where('tenant_id', $lead->tenant_id)->where('subject_type', self::SUBJECT)->where('subject_id', $lead->id)->orderByDesc('created_at')->get();
    }

    /** @param array{entry_type: string, body: string, follow_up_at?: ?string} $d */
    public function addActivity(object $lead, array $d, User $author): DiaryEntry
    {
        if (! in_array($d['entry_type'], self::ACTIVITY_TYPES, true)) {
            throw ValidationException::withMessages(['entry_type' => ['Unknown activity type.']]);
        }
        if ($d['entry_type'] === 'FOLLOW_UP' && empty($d['follow_up_at'])) {
            throw ValidationException::withMessages(['follow_up_at' => ['A follow-up needs a date.']]);
        }
        $entry = DiaryEntry::create([
            'tenant_id' => $lead->tenant_id, 'case_id' => null, 'subject_type' => self::SUBJECT, 'subject_id' => $lead->id,
            'author_id' => $author->id, 'entry_type' => $d['entry_type'], 'body' => $d['body'],
            'follow_up_at' => $d['follow_up_at'] ?? null, 'visibility' => 'INTERNAL', 'created_at' => now(),
        ]);
        DB::table('partner_leads')->where('id', $lead->id)->update(['updated_at' => now()]);
        $this->audit->record('crm.lead.activity_added', self::SUBJECT, $lead->id, ['entry_id' => $entry->id, 'entry_type' => $entry->entry_type]);

        return $entry;
    }

    private function query(User $user, string $tenantId)
    {
        $q = DB::table('partner_leads')->where('tenant_id', $tenantId);
        $broker = $this->scope->broker($user);
        if ($broker) {
            $q->where('partner_id', $broker->id);
        } elseif ($user->memberships()->where('tenant_id', $tenantId)->where('status', 'ACTIVE')->where('role_code', 'BROKER_STAFF')->exists()) {
            // Broker staff without a resolvable firm sees an empty book, never the tenant's.
            $q->whereRaw('1 = 0');
        }

        return $q;
    }

    private function leastLoadedPartner(string $tenantId): ?Partner
    {
        return Partner::where('tenant_id', $tenantId)->where('status', 'ACTIVE')->whereIn('type', ['AGENT', 'BROKER'])
            ->select('partners.*')
            ->selectSub(DB::table('partner_leads')->selectRaw('count(*)')->whereColumn('partner_leads.partner_id', 'partners.id')->whereIn('status', LeadPipeline::OPEN_STATUSES), 'open_leads')
            ->orderBy('open_leads')->orderBy('created_at')->orderBy('id')->first();
    }

    private function activePartner(string $partnerId, string $tenantId): Partner
    {
        $p = Str::isUuid($partnerId) ? Partner::where('tenant_id', $tenantId)->find($partnerId) : null;
        if (! $p || $p->status !== 'ACTIVE' || ! in_array($p->type, ['AGENT', 'BROKER'], true)) {
            throw ValidationException::withMessages(['partner_id' => ['Choose an active agent or broker in this organisation.']]);
        }

        return $p;
    }

    private function assertMember(string $userId, string $tenantId): void
    {
        $ok = Str::isUuid($userId) && DB::table('tenant_memberships')->where(['tenant_id' => $tenantId, 'user_id' => $userId, 'status' => 'ACTIVE'])->exists();
        if (! $ok) {
            throw ValidationException::withMessages(['assigned_user_id' => ['Choose an active member of this organisation.']]);
        }
    }

    private function history(string $leadId, ?string $fromP, ?string $toP, ?string $fromU, ?string $toU, string $rule, ?string $reason, User $actor): void
    {
        DB::table('partner_lead_assignments')->insert([
            'id' => (string) Str::uuid(), 'lead_id' => $leadId, 'from_partner_id' => $fromP, 'to_partner_id' => $toP,
            'from_user_id' => $fromU, 'to_user_id' => $toU, 'rule' => $rule, 'reason' => $reason, 'actor_id' => $actor->id, 'occurred_at' => now(),
        ]);
    }
}
