<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\MobileCompletion;

use App\Application\Audit\AuditWriter;
use App\Application\Claims\MobileClaimService;
use App\Application\Identity\PartyResolver;
use App\Application\Policies\MobileWalletService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Claim;
use App\Models\ClaimDispute;
use App\Models\UserNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * claim/[id]/{incident,checklist,inspection,repair,settlement,appeal} and
 * claim/emergency. Incident, inspection and repair details live inside
 * claims.loss_details (the claim's schemaless detail column) under their
 * own keys; the settlement view is derived from claim_decisions +
 * claim_payments; an appeal is a ClaimDispute; emergency assistance opens
 * an URGENT support ticket the operations desk works from.
 */
final class MobileClaimCompletionController
{
    private const INCIDENT_DEFAULTS = ['incident_type' => 'OTHER', 'police_report_number' => null, 'latitude' => null, 'longitude' => null, 'injuries_reported' => false, 'vehicle_drivable' => true, 'towing_required' => false, 'declaration_confirmed' => false];

    public function __construct(private MobileClaimService $claims, private MobileWalletService $wallet, private PartyResolver $parties, private AuditWriter $audit) {}

    public function incident(string $claim, Request $request): JsonResponse
    {
        $c = $this->owned($claim, $request);

        return response()->json(['data' => $this->incidentOf($c)]);
    }

    public function saveIncident(string $claim, Request $request): JsonResponse
    {
        $data = $request->validate([
            'incident_type' => 'sometimes|string|max:64', 'police_report_number' => 'nullable|string|max:120', 'latitude' => 'nullable|numeric', 'longitude' => 'nullable|numeric',
            'injuries_reported' => 'sometimes|boolean', 'vehicle_drivable' => 'sometimes|boolean', 'towing_required' => 'sometimes|boolean', 'declaration_confirmed' => 'sometimes|boolean',
        ]);
        $c = $this->owned($claim, $request);
        $details = $c->loss_details ?? [];
        $details['incident'] = array_merge(self::INCIDENT_DEFAULTS, $details['incident'] ?? [], $data);
        $c->update(['loss_details' => $details, 'version' => $c->version + 1]);
        if (! empty($data['declaration_confirmed'])) {
            $this->audit->record('claim.declaration.confirmed', 'claim', $c->id, []);
        }

        return response()->json(['data' => $this->incidentOf($c->refresh())]);
    }

    public function evidenceRequirements(string $claim, Request $request): JsonResponse
    {
        $c = $this->owned($claim, $request)->load('policy');
        $line = $c->policy?->terms_snapshot['line_code'] ?? 'MOTOR';
        $requirements = match ($line) {
            'HOME' => [['DAMAGE_PHOTO', 'Photos of the damage', true, 'Clear photos of every damaged area and item.'], ['PROOF_OF_OWNERSHIP', 'Proof of ownership', true, 'Receipts, invoices or photos showing the items before the loss.'], ['REPAIR_ESTIMATE', 'Repair estimate', false, 'A quote from a repairer or contractor.'], ['POLICE_REPORT', 'Police report', false, 'Required for theft or vandalism.']],
            'TRAVEL' => [['MEDICAL_REPORT', 'Medical report', true, 'Report and invoices from the treating facility.'], ['TRAVEL_DOCUMENTS', 'Tickets and boarding passes', true, 'Proof of travel dates.'], ['RECEIPTS', 'Receipts', false, 'Any expense you are claiming.']],
            'HEALTH' => [['MEDICAL_REPORT', 'Medical report', true, 'Diagnosis and treatment summary.'], ['INVOICES', 'Invoices and prescriptions', true, 'Itemised bills from the provider.']],
            default => [['DAMAGE_PHOTO', 'Photos of the damage', true, 'Front, rear, both sides and close-ups of the damage.'], ['POLICE_REPORT', 'Police report (procès-verbal)', true, 'Required for any collision involving a third party.'], ['DRIVER_LICENCE', 'Driver\'s licence', true, 'Licence of the person driving at the time.'], ['REPAIR_ESTIMATE', 'Repair estimate', false, 'A garage quote speeds up settlement.'], ['THIRD_PARTY_DETAILS', 'Third-party details', false, 'Insurer and registration of the other vehicle.']],
        };
        $uploaded = DB::table('claim_documents')->where('claim_id', $c->id)->get()->keyBy('evidence_type');

        return response()->json(['data' => collect($requirements)->map(function ($r) use ($uploaded) {
            [$key, $label, $required, $guidance] = $r;
            $doc = $uploaded->get($key);
            $status = ! $doc ? 'MISSING' : match ($doc->status) { 'VERIFIED' => 'VERIFIED', 'REJECTED' => 'REJECTED', default => 'UPLOADED' };

            return compact('key', 'label', 'required', 'status', 'guidance');
        })->values()]);
    }

    public function inspection(string $claim, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->inspectionOf($this->owned($claim, $request))]);
    }

    public function rescheduleInspection(string $claim, Request $request): JsonResponse
    {
        $data = $request->validate(['appointment_at' => 'required|date|after:now']);
        $c = $this->owned($claim, $request);
        $details = $c->loss_details ?? [];
        $details['inspection'] = array_merge($details['inspection'] ?? [], ['appointment_at' => \Carbon\Carbon::parse($data['appointment_at'])->toIso8601String(), 'status' => 'RESCHEDULED']);
        $c->update(['loss_details' => $details, 'version' => $c->version + 1]);
        $this->audit->record('claim.inspection.rescheduled', 'claim', $c->id, ['appointment_at' => $details['inspection']['appointment_at']]);

        return response()->json(['data' => $this->inspectionOf($c->refresh())]);
    }

    public function repair(string $claim, Request $request): JsonResponse
    {
        $c = $this->owned($claim, $request);
        $r = ($c->loss_details ?? [])['repair'] ?? [];

        return response()->json(['data' => [
            'claim_id' => $c->id, 'status' => $r['status'] ?? 'NOT_STARTED', 'garage_name' => $r['garage_name'] ?? null, 'estimate_minor' => $r['estimate_minor'] ?? $c->estimated_loss_minor,
            'approved_minor' => $r['approved_minor'] ?? $c->approved_amount_minor, 'deductible_minor' => $r['deductible_minor'] ?? null, 'authorization_reference' => $r['authorization_reference'] ?? null,
        ]]);
    }

    public function settlement(string $claim, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->settlementOf($this->owned($claim, $request))]);
    }

    public function decideSettlement(string $claim, Request $request): JsonResponse
    {
        $data = $request->validate(['decision' => 'required|in:ACCEPT,REJECT']);
        $c = $this->owned($claim, $request);
        $decision = DB::table('claim_decisions')->where('claim_id', $c->id)->orderByDesc('created_at')->first();
        abort_unless($decision, 422, 'There is no settlement offer to decide on yet.');
        $details = $c->loss_details ?? [];
        $details['settlement'] = array_merge($details['settlement'] ?? [], ['customer_decision' => $data['decision'], 'decided_at' => now()->toIso8601String()]);
        $c->update(['loss_details' => $details, 'version' => $c->version + 1]);
        if ($data['decision'] === 'REJECT' && in_array($c->status, ['DECLINED', 'PARTIALLY_APPROVED'], true)) {
            ClaimDispute::firstOrCreate(['claim_id' => $c->id, 'status' => 'OPEN'], ['reference' => 'DSP-'.strtoupper(Str::random(12)), 'reason_code' => 'SETTLEMENT_REJECTED', 'statement' => 'Customer rejected the settlement offer from the app.', 'opened_by' => $request->user()->id]);
        }
        $this->audit->record('claim.settlement.customer_decision', 'claim', $c->id, ['decision' => $data['decision']]);

        return response()->json(['data' => $this->settlementOf($c->refresh())]);
    }

    public function appeal(string $claim, Request $request): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|min:10|max:4000']);
        $c = $this->owned($claim, $request);
        abort_unless(in_array($c->status, ['DECLINED', 'PARTIALLY_APPROVED', 'PAID', 'CLOSED'], true), 422, 'Only a decided claim can be appealed.');
        $dispute = ClaimDispute::firstOrCreate(['claim_id' => $c->id, 'status' => 'OPEN'], ['reference' => 'DSP-'.strtoupper(Str::random(12)), 'reason_code' => 'CUSTOMER_APPEAL', 'statement' => $data['reason'], 'opened_by' => $request->user()->id]);
        if (in_array($c->status, ['DECLINED', 'PARTIALLY_APPROVED'], true)) {
            $c->update(['status' => 'DISPUTED', 'version' => $c->version + 1]);
        }
        $this->audit->record('claim.appealed', 'claim', $c->id, ['dispute_id' => $dispute->id]);
        UserNotification::notify($request->user(), 'CLAIM', 'Appeal received', "Your appeal on claim {$c->claim_number} was received (ref {$dispute->reference}). A claims officer will respond within 5 business days.", 'INFO', "/claim/{$c->id}", $c->tenant_id);

        return response()->json(['data' => $c->refresh()->load('policy')], 201);
    }

    public function emergency(Request $request): JsonResponse
    {
        $data = $request->validate(['policy_id' => 'required|uuid', 'service' => 'required|in:MEDICAL,POLICE,TOWING', 'location' => 'required|string|min:3|max:500', 'callback_phone' => 'required|string|max:32']);
        $policy = $this->wallet->policy($data['policy_id'], $request->user(), app(TenantContext::class)->id());
        $party = $this->parties->forUser($request->user());
        $id = (string) Str::uuid();
        $reference = 'EMG-'.now()->format('ymd').'-'.strtoupper(Str::random(6));
        DB::table('support_tickets')->insert([
            'id' => $id, 'tenant_id' => $policy->tenant_id, 'party_id' => $party->id, 'ticket_number' => $reference, 'type' => 'SUPPORT', 'category' => 'EMERGENCY_'.$data['service'], 'priority' => 'URGENT', 'status' => 'OPEN',
            'subject' => $data['service'].' assistance requested', 'description' => "Location: {$data['location']}\nCallback: {$data['callback_phone']}\nPolicy: {$policy->policy_number}",
            'sla_due_at' => now()->addMinutes(30), 'idempotency_key' => $request->header('Idempotency-Key') ?: $id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('support_ticket_events')->insert(['id' => (string) Str::uuid(), 'support_ticket_id' => $id, 'type' => 'CREATED', 'to_status' => 'OPEN', 'actor_id' => $request->user()->id, 'message' => 'Emergency assistance requested from the mobile app.', 'metadata' => json_encode(['sender' => 'CUSTOMER', 'service' => $data['service'], 'location' => $data['location']]), 'occurred_at' => now()]);
        $this->audit->record('claim.emergency.requested', 'support_ticket', $id, ['service' => $data['service']]);

        return response()->json(['data' => ['id' => $id, 'status' => 'DISPATCHING', 'reference' => $reference]], 201);
    }

    private function owned(string $id, Request $request): Claim
    {
        return $this->claims->owned($id, $request->user(), app(TenantContext::class)->id());
    }

    private function incidentOf(Claim $c): array
    {
        return ['claim_id' => $c->id] + array_merge(self::INCIDENT_DEFAULTS, ($c->loss_details ?? [])['incident'] ?? []);
    }

    private function inspectionOf(Claim $c): array
    {
        $i = ($c->loss_details ?? [])['inspection'] ?? [];

        return [
            'id' => $c->id.':inspection', 'claim_id' => $c->id, 'status' => $i['status'] ?? 'NOT_SCHEDULED',
            'appointment_at' => $i['appointment_at'] ?? now()->addDays(3)->setTime(10, 0)->toIso8601String(), 'location' => $i['location'] ?? 'To be confirmed by the assessor',
            'surveyor_name' => $i['surveyor_name'] ?? null, 'contact_phone' => $i['contact_phone'] ?? null, 'notes' => $i['notes'] ?? null,
        ];
    }

    private function settlementOf(Claim $c): array
    {
        $decision = DB::table('claim_decisions')->where('claim_id', $c->id)->orderByDesc('created_at')->first();
        $payment = DB::table('claim_payments')->where('claim_id', $c->id)->orderByDesc('created_at')->first();
        $s = ($c->loss_details ?? [])['settlement'] ?? [];
        $offered = (int) ($decision->approved_amount_minor ?? $c->approved_amount_minor ?? 0);
        $deductible = (int) ($s['deductible_minor'] ?? 0);

        return [
            'id' => $decision->id ?? $c->id.':settlement', 'claim_id' => $c->id,
            'status' => $decision ? ($s['customer_decision'] ?? null ? 'CUSTOMER_'.$s['customer_decision'].'ED' : ($decision->status ?? 'OFFERED')) : 'PENDING_DECISION',
            'offered_minor' => $offered, 'deductible_minor' => $deductible, 'net_minor' => max(0, $offered - $deductible), 'currency' => 'XAF',
            'payment_status' => $payment->status ?? 'NOT_STARTED', 'payment_reference' => $payment->external_reference ?? null,
            'decision_deadline' => $s['decision_deadline'] ?? ($decision ? \Carbon\Carbon::parse($decision->created_at)->addDays(14)->toIso8601String() : now()->addDays(14)->toIso8601String()),
            'terms' => $s['terms'] ?? ($decision->rationale ?? 'The settlement terms will appear here once the insurer has decided on your claim.'),
        ];
    }
}
