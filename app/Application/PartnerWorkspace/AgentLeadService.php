<?php

declare(strict_types=1);

namespace App\Application\PartnerWorkspace;

use App\Application\Agents\AgentClientIntakeService;
use App\Application\Audit\AuditWriter;
use App\Models\Partner;
use App\Models\TenantCustomer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Agent leads (partner_leads) and consented client intake.
 *
 * Consent: the app only asserts that the agent captured the client's
 * consent (consent_confirmed = true, an explicit checkbox). The consent
 * evidence reference is minted HERE, server-side, and stored on the
 * Consent row by AgentClientIntakeService — the app never invents one.
 */
final class AgentLeadService
{
    public const NOTICE_VERSION = 'agent-2026-01';

    /** Statuses an agent may set directly; CONVERTED only via convert(). */
    public const MANUAL_STATUSES = LeadPipeline::MANUAL_STATUSES;

    public function __construct(
        private readonly PartnerWorkspaceScope $scope,
        private readonly AgentClientIntakeService $intake,
        private readonly AuditWriter $audit,
    ) {}

    public function list(User $user, string $tenantId)
    {
        $partner = $this->scope->agent($user);

        return DB::table('partner_leads')->where('tenant_id', $tenantId)->where('partner_id', $partner->id)
            ->orderByRaw("CASE status WHEN 'NEW' THEN 0 WHEN 'CONTACTED' THEN 1 WHEN 'QUALIFIED' THEN 2 WHEN 'CONVERTED' THEN 3 ELSE 4 END")
            ->orderByDesc('created_at')->limit(200)->get();
    }

    public function create(array $data, User $user, string $tenantId): object
    {
        $partner = $this->scope->activeAgent($user);
        $id = (string) Str::uuid();
        DB::table('partner_leads')->insert([
            'id' => $id, 'tenant_id' => $tenantId, 'partner_id' => $partner->id, 'full_name' => $data['full_name'], 'phone_e164' => $data['phone_e164'],
            'city' => $data['city'] ?? null, 'product_interest' => isset($data['product_interest']) ? strtoupper($data['product_interest']) : null, 'notes' => $data['notes'] ?? null,
            'status' => 'NEW', 'assigned_user_id' => $user->id, 'source' => 'AGENT', 'status_changed_at' => now(), 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->audit->record('agent.lead.created', 'partner_lead', $id, []);

        return DB::table('partner_leads')->find($id);
    }

    public function update(string $leadId, array $data, User $user, string $tenantId): object
    {
        $lead = $this->find($leadId, $user, $tenantId);
        if ($lead->status === 'CONVERTED') {
            throw ValidationException::withMessages(['status' => ['This lead is already a client.']]);
        }
        $changes = array_intersect_key($data, array_flip(['status', 'notes', 'city', 'product_interest', 'lost_reason']));
        if (isset($changes['status']) && $changes['status'] !== $lead->status) {
            LeadPipeline::assert($lead->status, $changes['status']);
            $changes['status_changed_at'] = now();
        }
        DB::table('partner_leads')->where('id', $lead->id)->update([...$changes, 'updated_at' => now()]);
        $this->audit->record('agent.lead.updated', 'partner_lead', $lead->id, ['fields' => array_keys($changes)]);

        return DB::table('partner_leads')->find($lead->id);
    }

    /** @return array{lead: object, customer: TenantCustomer, consent_reference: string} */
    public function convert(string $leadId, array $data, User $user, string $tenantId): array
    {
        $lead = $this->find($leadId, $user, $tenantId);
        if ($lead->status === 'CONVERTED') {
            throw ValidationException::withMessages(['status' => ['This lead is already a client.']]);
        }

        return DB::transaction(function () use ($lead, $data, $user, $tenantId) {
            $result = $this->registerClient(['full_name' => $lead->full_name, 'phone_e164' => $lead->phone_e164, 'city' => $data['city'] ?? $lead->city], $user, $tenantId);
            DB::table('partner_leads')->where('id', $lead->id)->update(['status' => 'CONVERTED', 'converted_customer_id' => $result['customer']->id, 'converted_at' => now(), 'updated_at' => now()]);
            $this->audit->record('agent.lead.converted', 'partner_lead', $lead->id, ['customer_id' => $result['customer']->id]);

            return ['lead' => DB::table('partner_leads')->find($lead->id)] + $result;
        });
    }

    /**
     * Consented intake: mints the consent reference and registers the client
     * through the Agent Mode intake service (consent + origin lock).
     *
     * @return array{customer: TenantCustomer, consent_reference: string}
     */
    public function registerClient(array $data, User $user, string $tenantId): array
    {
        $reference = 'CNS-'.now()->format('Ymd').'-'.strtoupper(Str::random(10));

        return DB::transaction(function () use ($data, $user, $tenantId, $reference) {
            $result = $this->intake->register([
                'type' => 'PERSON', 'display_name' => $data['full_name'], 'phone_e164' => $data['phone_e164'],
                'notice_version' => self::NOTICE_VERSION, 'evidence_reference' => $reference, 'consent' => true,
            ], $user, $tenantId);
            $customer = TenantCustomer::with('party.contacts')->findOrFail($result['id']);
            if (! empty($data['city'])) {
                $existing = DB::table('party_addresses')->where(['party_id' => $customer->party_id, 'type' => 'HOME'])->value('id');
                DB::table('party_addresses')->updateOrInsert(['party_id' => $customer->party_id, 'type' => 'HOME'], ['id' => $existing ?? (string) Str::uuid(), 'city' => $data['city'], 'country_code' => 'CM', 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->audit->record('agent.client.consent_captured', 'tenant_customer', $customer->id, ['consent_reference' => $reference]);

            return ['customer' => $customer, 'consent_reference' => $reference];
        });
    }

    /** Read access: any agent status may view its own history. */
    public function show(string $leadId, User $user, string $tenantId): object
    {
        return $this->find($leadId, $user, $tenantId, false);
    }

    private function find(string $leadId, User $user, string $tenantId, bool $mutating = true): object
    {
        $partner = $mutating ? $this->scope->activeAgent($user) : $this->scope->agent($user);
        $lead = Str::isUuid($leadId) ? DB::table('partner_leads')->where(['id' => $leadId, 'tenant_id' => $tenantId, 'partner_id' => $partner->id])->first() : null;
        abort_unless($lead, 404);

        return $lead;
    }
}
