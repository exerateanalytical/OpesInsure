<?php

declare(strict_types=1);

namespace App\Application\Claims;

use App\Application\Audit\AuditWriter;
use App\Application\Identity\PartyResolver;
use App\Models\Claim;
use App\Models\ClaimDraft;
use App\Models\Policy;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Customer claim drafts. A draft is a partial FNOL payload kept in claim_drafts — never a Claim — so it
 * consumes no claim number, is invisible to staff queues and starts no SLA or notification. submit()
 * validates the payload with the same rules as POST /mobile/claims and files it through the one FNOL path
 * (MobileClaimService::fnol → FnolService), using a draft-derived idempotency key so a retried submit
 * replays the same claim. Ownership: the draft's party_id is always the caller's own resolved party;
 * anyone else's draft is a 404.
 */
final class MobileClaimDraftService
{
    public const PAYLOAD_KEYS = ['incident_at', 'incident_location', 'description', 'incident_type', 'injuries_reported',
        'police_report_filed', 'police_reference', 'estimated_loss_minor', 'client_state', 'latitude', 'longitude', 'evidence'];

    /** Draft-only keys: never part of the FNOL payload itself (coordinates and evidence are applied after filing). */
    private const NON_FNOL_KEYS = ['client_state', 'latitude', 'longitude', 'evidence'];

    public function __construct(private PartyResolver $parties, private MobileClaimService $claims, private AuditWriter $audit) {}

    /** Rules for saving a draft: every field optional, same shapes as the FNOL rules. */
    public static function draftRules(): array
    {
        return [
            'policy_id' => 'nullable|uuid',
            'incident_at' => 'nullable|date',
            'incident_location' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:5000',
            'incident_type' => 'nullable|string|max:64',
            'injuries_reported' => 'sometimes|boolean',
            'police_report_filed' => 'sometimes|boolean',
            'police_reference' => 'nullable|string|max:120',
            'estimated_loss_minor' => 'nullable|integer|min:0',
            'client_state' => 'nullable|array|max:30',
            ...\App\Domain\Geo\Coordinates::RULES,
            // Evidence gathered before the claim exists (wizard step 3): files the customer already uploaded
            // (POST /mobile/documents → document_id, or a finished resumable upload → upload_session_id). They are
            // attached to the claim when the draft is submitted, with the same ownership checks as POST .../evidence.
            'evidence' => 'nullable|array|max:20',
            'evidence.*.document_id' => 'nullable|uuid|required_without:evidence.*.upload_session_id',
            'evidence.*.upload_session_id' => 'nullable|uuid',
            'evidence.*.evidence_type' => 'required|string|max:64',
            'evidence.*.name' => 'nullable|string|max:255',
            'evidence.*.mime_type' => 'nullable|string|max:100',
            'evidence.*.size_bytes' => 'nullable|integer|min:0',
        ];
    }

    /** Same rules as POST /mobile/claims (MobileClaimController::store). */
    public static function fnolRules(): array
    {
        return [
            'policy_id' => 'required|uuid',
            'incident_at' => 'required|date|before_or_equal:now',
            'incident_location' => 'nullable|string|max:255',
            'description' => 'required|string|min:10|max:5000',
            'incident_type' => 'nullable|string|max:64',
            'injuries_reported' => 'sometimes|boolean',
            'police_report_filed' => 'sometimes|boolean',
            'police_reference' => 'nullable|string|max:120',
            'estimated_loss_minor' => 'nullable|integer|min:0',
        ];
    }

    public function list(User $user, string $tenantId): Collection
    {
        return $this->query($user, $tenantId)->whereNull('claim_id')->with('policy')->orderByDesc('updated_at')->get();
    }

    public function show(string $id, User $user, string $tenantId): ClaimDraft
    {
        return $this->owned($id, $user, $tenantId)->load('policy');
    }

    public function create(array $data, User $user, string $tenantId): ClaimDraft
    {
        $party = $this->party($user);
        $this->assertPolicy($data['policy_id'] ?? null, $party->id, $tenantId);
        $draft = ClaimDraft::create([
            'tenant_id' => $tenantId, 'party_id' => $party->id, 'user_id' => $user->id,
            'policy_id' => $data['policy_id'] ?? null, 'payload' => $this->payload([], $data),
        ]);
        $this->audit->record('claim.draft.saved', 'claim_draft', $draft->id, ['policy_id' => $draft->policy_id]);

        return $draft->load('policy');
    }

    public function update(string $id, array $data, User $user, string $tenantId): ClaimDraft
    {
        $draft = $this->owned($id, $user, $tenantId);
        if (array_key_exists('policy_id', $data)) {
            $this->assertPolicy($data['policy_id'], $draft->party_id, $tenantId);
            $draft->policy_id = $data['policy_id'];
        }
        $draft->payload = $this->payload($draft->payload ?? [], $data);
        $draft->save();
        $this->audit->record('claim.draft.saved', 'claim_draft', $draft->id, ['policy_id' => $draft->policy_id]);

        return $draft->load('policy');
    }

    public function delete(string $id, User $user, string $tenantId): void
    {
        $draft = $this->owned($id, $user, $tenantId);
        $draft->delete();
        $this->audit->record('claim.draft.discarded', 'claim_draft', $id, []);
    }

    /**
     * Files the draft as a real claim (FNOL). Returns [claim, created, evidence]; a second submit replays the first
     * claim (evidence is attached only on the first). $declarationConfirmed records the customer's declaration on
     * the claim (same as PUT /mobile/claims/{id}/incident declaration_confirmed). Draft coordinates go through the
     * incident path, draft evidence is attached one by one: a file that cannot be attached is reported in
     * `evidence.failed` and never undoes the filed claim (the customer can add it again from the claim).
     *
     * @return array{0: Claim, 1: bool, 2: array{attached: int, pending: int, failed: list<array{index: int, name: ?string, evidence_type: string}>}}
     */
    public function submit(string $id, User $user, string $tenantId, bool $declarationConfirmed = false): array
    {
        $draft = ClaimDraft::where('tenant_id', $tenantId)->where('party_id', $this->party($user)->id)->find($id)
            ?? throw new ModelNotFoundException;
        if ($draft->claim_id) {
            return [Claim::findOrFail($draft->claim_id), false, ['attached' => 0, 'pending' => 0, 'failed' => []]];
        }
        $input = array_intersect_key($draft->payload ?? [], array_flip(self::PAYLOAD_KEYS)) + ['policy_id' => $draft->policy_id];
        foreach (self::NON_FNOL_KEYS as $key) {
            unset($input[$key]);
        }
        $v = Validator::make($input, self::fnolRules());
        if ($v->fails()) {
            throw ValidationException::withMessages(['draft' => __('wave12.claim_draft_incomplete')] + $v->errors()->toArray());
        }
        $data = $v->validated();
        $data['idempotency_key'] = 'claim-draft:'.$draft->id;
        $claim = $this->claims->fnol($data, $user, $tenantId);
        DB::table('claim_drafts')->where('id', $draft->id)->update(['claim_id' => $claim->id, 'submitted_at' => now(), 'updated_at' => now()]);
        $this->audit->record('claim.draft.submitted', 'claim_draft', $draft->id, ['claim_id' => $claim->id]);

        $payload = $draft->payload ?? [];
        $incident = array_filter(['latitude' => $payload['latitude'] ?? null, 'longitude' => $payload['longitude'] ?? null], fn ($v) => $v !== null);
        if ($declarationConfirmed) {
            $incident['declaration_confirmed'] = true;
        }
        if ($incident !== []) {
            $claim = app(ClaimIncidentService::class)->save($claim->id, $incident, $user, $tenantId);
        }

        return [$claim, true, $this->attachEvidence($claim, (array) ($payload['evidence'] ?? []), $user, $tenantId)];
    }

    /** @return array{attached: int, pending: int, failed: list<array{index: int, name: ?string, evidence_type: string}>} */
    private function attachEvidence(Claim $claim, array $items, User $user, string $tenantId): array
    {
        $report = ['attached' => 0, 'pending' => 0, 'failed' => []];
        $service = app(MobileClaimEvidenceService::class);
        foreach (array_values($items) as $i => $item) {
            $type = (string) ($item['evidence_type'] ?? 'OTHER');
            $ref = ! empty($item['document_id']) ? ['document_id' => $item['document_id']] : ['upload_session_id' => $item['upload_session_id'] ?? null];
            try {
                if (! array_filter($ref)) {
                    throw new \InvalidArgumentException('no file');
                }
                // Own transaction (a savepoint when nested): one bad file never poisons the others.
                $result = DB::transaction(fn () => $service->attach($claim->id, $ref + ['evidence_type' => $type, 'purpose' => 'CLAIM_EVIDENCE'], $user, $tenantId));
                MobileClaimEvidenceService::deferred($result) ? $report['pending']++ : $report['attached']++;
            } catch (\Throwable $e) {
                if (! $e instanceof ValidationException && ! $e instanceof \InvalidArgumentException && ! $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                    report($e);
                }
                $report['failed'][] = ['index' => $i, 'name' => isset($item['name']) ? (string) $item['name'] : null, 'evidence_type' => $type];
            }
        }

        return $report;
    }

    private function payload(array $current, array $data): array
    {
        foreach (self::PAYLOAD_KEYS as $k) {
            if (array_key_exists($k, $data)) {
                $current[$k] = $data[$k];
            }
        }

        return $current;
    }

    private function assertPolicy(?string $policyId, string $partyId, string $tenantId): void
    {
        if ($policyId && ! Policy::where(['id' => $policyId, 'tenant_id' => $tenantId, 'party_id' => $partyId])->exists()) {
            throw ValidationException::withMessages(['policy_id' => __('wave7.claimant_invalid')]);
        }
    }

    private function owned(string $id, User $user, string $tenantId): ClaimDraft
    {
        $draft = $this->query($user, $tenantId)->find($id) ?? throw new ModelNotFoundException;
        if ($draft->claim_id) {
            throw ValidationException::withMessages(['draft' => __('wave12.claim_draft_submitted')]);
        }

        return $draft;
    }

    private function query(User $user, string $tenantId)
    {
        $party = $this->parties->forUser($user);

        return ClaimDraft::where('tenant_id', $tenantId)
            ->when($party, fn ($q) => $q->where('party_id', $party->id), fn ($q) => $q->whereRaw('1 = 0'));
    }

    private function party(User $user)
    {
        return $this->parties->forUser($user) ?? throw ValidationException::withMessages(['party' => __('wave12.claim_no_party')]);
    }
}
