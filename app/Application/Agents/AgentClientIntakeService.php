<?php

declare(strict_types=1);

namespace App\Application\Agents;

use App\Application\Customers\AttributionService;
use App\Application\Customers\CustomerService;
use App\Application\Customers\PartyService;
use App\Application\Identity\PartyResolver;
use App\Application\Privacy\ConsentService;
use App\Models\Partner;
use App\Models\Party;
use App\Models\Tenant;
use App\Models\TenantCustomer;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Agent-mode "register a client on the client's behalf" — the mobile
 * counterpart to the staff-facing CustomerController::store(), which this
 * deliberately does NOT reuse directly: that endpoint trusts a
 * caller-supplied partner_id/origin_type and carries no permission gate at
 * all (see routes/api.php — `Route::apiResource('customers', ...)` has no
 * `permission:` middleware), which is fine for the back-office staff who can
 * already reach it but is exactly the "mobile app decides/transfers client
 * ownership" hazard the Patch 4 merge guide prohibits for an agent's own
 * app. This service composes the SAME underlying domain services
 * (PartyService, CustomerService, ConsentService, AttributionService) but
 * hard-codes partner_id to the caller's OWN resolved Partner and origin_type
 * to 'AGENT' — never accepted from the request — so an agent can only ever
 * attribute a new client to themselves.
 *
 * Reused verbatim (not reimplemented) for offline-queue replay: see
 * App\Application\Sync\SyncOperationDispatchService, which calls register()
 * with the exact same validation via rules().
 */
final class AgentClientIntakeService
{
    public function __construct(
        private readonly AgentPartnerResolver $partners,
        private readonly PartyService $parties,
        private readonly CustomerService $customers,
        private readonly ConsentService $consents,
        private readonly AttributionService $attributions,
        private readonly PartyResolver $resolver,
    ) {
    }

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'type' => 'required|in:PERSON,ORGANIZATION',
            'display_name' => 'required|string|max:160',
            'phone_e164' => 'required|string|max:32',
            'email' => 'nullable|email|max:190',
            'date_of_birth' => 'nullable|date|before:-18 years',
            'identifier_type' => 'nullable|required_with:identifier_value|in:NATIONAL_ID,PASSPORT,RCCM',
            'identifier_value' => 'nullable|required_with:identifier_type|string|max:100',
            'identifier_country' => 'nullable|string|size:2',
            'notice_version' => 'required|string|max:32',
            'evidence_reference' => 'required|string|max:255',
            'consent' => 'required|accepted',
        ];
    }

    /** Own clients only — every party attributed to this agent's own Partner. */
    public function list(User $user, string $tenantId, int $perPage = 20): LengthAwarePaginator
    {
        $partner = $this->partners->resolve($user);

        return TenantCustomer::with('party.contacts')
            ->where('tenant_id', $tenantId)
            ->whereHas('party.attributions', fn ($q) => $q->where('partner_id', $partner->id)->where('status', 'ACTIVE'))
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    public function show(string $customerId, User $user, string $tenantId): TenantCustomer
    {
        $partner = $this->partners->resolve($user);

        $customer = TenantCustomer::with(['party.contacts', 'party.identifiers', 'party.consents'])
            ->where('tenant_id', $tenantId)
            ->whereHas('party.attributions', fn ($q) => $q->where('partner_id', $partner->id)->where('status', 'ACTIVE'))
            ->find($customerId);

        if (! $customer) {
            $exists = TenantCustomer::where('tenant_id', $tenantId)->where('id', $customerId)->exists();

            throw $exists ? new AuthorizationException : new ModelNotFoundException;
        }

        return $customer;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{id: string, party_id: string, customer_number: string, attribution_id: string}
     */
    public function register(array $data, User $user, string $tenantId): array
    {
        return $this->registerFor($this->partners->resolveActive($user), $data, $user, $tenantId);
    }

    /**
     * Broker client onboarding (owner decision "partners quote for their own
     * clients and new clients"): the same intake, origin-locked to the
     * caller's own BROKER Partner — never one taken from the request.
     *
     * @param  array<string, mixed>  $data
     * @return array{id: string, party_id: string, customer_number: string, attribution_id: string}
     */
    public function registerForBroker(array $data, User $user, string $tenantId): array
    {
        $partner = $this->resolver->partnerForUser($user);
        if (! $partner || $partner->type !== 'BROKER') {
            throw new AuthorizationException(__('quotes.partner_outside_book'));
        }
        if ($partner->status !== 'ACTIVE') {
            throw ValidationException::withMessages(['partner' => [__('wave1.partner_not_active')]]);
        }

        return $this->registerFor($partner, $data, $user, $tenantId);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{id: string, party_id: string, customer_number: string, attribution_id: string}
     */
    private function registerFor(Partner $partner, array $data, User $user, string $tenantId): array
    {
        $phone = $this->normalizePhone((string) $data['phone_e164']);

        if (! preg_match('/^\+[1-9]\d{7,14}$/', $phone)) {
            throw ValidationException::withMessages(['phone_e164' => [__('wave12.agent_client_phone_invalid')]]);
        }

        $tenant = Tenant::findOrFail($tenantId);

        return DB::transaction(function () use ($data, $phone, $partner, $tenant, $user) {
            $partyId = DB::table('party_contacts')->where(['type' => 'PHONE', 'normalized_value' => $phone])->value('party_id');
            $party = $partyId ? Party::findOrFail($partyId) : $this->parties->create([...$data, 'phone_e164' => $phone]);

            $customer = TenantCustomer::where(['tenant_id' => $tenant->id, 'party_id' => $party->id])->first()
                ?? $this->customers->register($tenant, $party, ['registered_by_partner_id' => $partner->id]);

            $this->consents->grant($party, $tenant, 'INSURANCE_SERVICES', $data['notice_version'], 'ASSISTED', [
                'affirmed' => true, 'reference' => $data['evidence_reference'], 'captured_by_partner_id' => $partner->id,
            ], $user);

            // lock(), NOT lockOrPreserve(): the staff endpoint's "preserve
            // silently and flag origin_preserved" behaviour is wrong for an
            // agent's own app — it would hand back a 201 for a client whose
            // commission origin actually belongs to a competitor, which the
            // agent would reasonably read as "this client is now mine".
            // lock() is still idempotent for the SAME agent re-registering the
            // same client (it returns the existing row when partner+origin
            // match); only a genuinely different owner throws, surfacing
            // wave1.attribution_already_locked — "Open a dispute instead." The
            // throw rolls this whole transaction back, so no half-registered
            // party/customer/consent is left behind for a client the agent
            // cannot own.
            $attribution = $this->attributions->lock($party, $partner, $partner->type, $data['notice_version'], $data['evidence_reference'], $user);

            return [
                'id' => $customer->id,
                'party_id' => $party->id,
                'customer_number' => $customer->customer_number,
                'attribution_id' => $attribution->id,
            ];
        });
    }

    /** Cameroon-first normalization: accepts a local 9-digit number, a 00-prefixed, or an already-E.164 value. */
    private function normalizePhone(string $raw): string
    {
        $digits = preg_replace('/[^\d+]/', '', $raw) ?? '';

        if (str_starts_with($digits, '+')) {
            return $digits;
        }
        if (str_starts_with($digits, '00')) {
            return '+'.substr($digits, 2);
        }
        if (str_starts_with($digits, '237')) {
            return '+'.$digits;
        }
        if (preg_match('/^\d{9}$/', $digits)) {
            return '+237'.$digits;
        }

        return $digits;
    }
}
