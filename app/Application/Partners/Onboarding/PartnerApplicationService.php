<?php

declare(strict_types=1);

namespace App\Application\Partners\Onboarding;

use App\Application\Audit\AuditWriter;
use App\Application\Customers\PartyService;
use App\Application\Identity\InvitationService;
use App\Application\Partners\AgentHierarchyService;
use App\Application\Partners\BulkOnboarding\BrokerOnboardingTarget;
use App\Application\Partners\LicensingService;
use App\Application\Tenancy\TenantLifecycleService;
use App\Models\Carrier;
use App\Models\Partner;
use App\Models\PartnerApplication;
use App\Models\Party;
use App\Models\Tenant;
use App\Models\TenantBranch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Partner self-service application (/partners/apply): insurers, brokers and commercial agents apply; a platform admin
 * reviews (maker-checker, IntakeReview) and approval provisions the organisation through the existing onboarding code:
 *
 *   BROKER  → BrokerOnboardingTarget::import (the S9 bulk-onboarding row import: BROKER tenant, party, partner or
 *             official-register link, licence, branches, BROKER_ADMIN invitation)
 *   INSURER → CARRIER tenant (TenantLifecycleService) + party + carrier + CARRIER partner (compliance.carrier_id)
 *             + optional licence + head office + CARRIER_SUPER_ADMIN invitation linked to the carrier
 *   AGENT   → AGENT partner under the brokerage that confirmed them (or an own AGENCY tenant when independent)
 *             + agent mandate licence + AGENT invitation
 *
 * The applicant verifies their phone (SMS, when a provider is configured) or email with a one-time code before the
 * application reaches the admins, and follows it on a secret status link.
 */
final class PartnerApplicationService
{
    public const TYPES = ['INSURER', 'BROKER', 'AGENT'];

    public const DOCUMENT_KINDS = ['licence', 'rccm', 'id'];

    /** Open (non-expired) statuses that block a second application for the same identifiers. */
    private const LIVE = ['UNVERIFIED', 'SUBMITTED', 'UNDER_REVIEW', 'INFO_REQUESTED'];

    public function __construct(
        private readonly PublicIntake $intake,
        private readonly IntakeReview $review,
        private readonly AuditWriter $audit,
        private readonly PartyService $parties,
        private readonly LicensingService $licensing,
        private readonly InvitationService $invitations,
        private readonly OrganisationProvisioner $provisioner,
        private readonly AgentHierarchyService $hierarchy,
    ) {}

    /** Active brokerages an agent may name (public list: names only). @return array<string, string> id => name */
    public function brokerages(): array
    {
        return Tenant::query()->where('type', 'BROKER')->where('status', 'ACTIVE')->where(fn ($q) => $q->whereNull('settings->demo')->orWhere('settings->demo', false))
            ->orderBy('legal_name')->get(['id', 'legal_name', 'trade_name'])->mapWithKeys(fn ($t) => [$t->id => $t->trade_name ?: $t->legal_name])->all();
    }

    /**
     * Normalises and checks the form. Returns the normalised fields.
     *
     * @param  array<string, mixed>  $data  validated request input
     */
    public function normalise(array $data): array
    {
        $s = fn (string $k) => ($v = trim((string) ($data[$k] ?? ''))) === '' ? null : preg_replace('/\s+/u', ' ', $v);
        $type = (string) $data['type'];
        $n = [
            'type' => $type, 'legal_name' => $s('legal_name'), 'trade_name' => $s('trade_name'), 'city' => $s('city'), 'address' => $s('address'),
            'rccm' => ($v = $s('rccm')) ? mb_strtoupper(str_replace(' ', '', $v)) : null,
            'niu' => ($v = $s('niu')) ? mb_strtoupper(preg_replace('/[\s\-]+/', '', $v)) : null,
            'licence_number' => ($v = $s('licence_number')) ? mb_strtoupper($v) : null,
            'licence_expires_on' => $s('licence_expires_on'),
            'applicant_name' => $s('applicant_name'), 'applicant_email' => mb_strtolower((string) $s('applicant_email')),
            'applicant_phone' => ($v = $s('applicant_phone')) ? BrokerOnboardingTarget::phone($v) : null,
            'org_phone' => ($v = $s('org_phone')) ? BrokerOnboardingTarget::phone($v) : null,
            'org_email' => ($v = $s('org_email')) ? mb_strtolower($v) : null,
            'brokerage_tenant_id' => $type === 'AGENT' && ! ($data['independent'] ?? false) ? $s('brokerage_tenant_id') : null,
            'locale' => in_array($data['locale'] ?? null, ['en', 'fr'], true) ? $data['locale'] : 'fr',
        ];
        $e = [];
        $required = ['legal_name', 'city', 'applicant_name', 'niu', ...($type !== 'AGENT' ? ['rccm', 'org_phone'] : []), ...($type !== 'INSURER' ? ['licence_number', 'licence_expires_on'] : [])];
        foreach ($required as $f) {
            if ($n[$f] === null) {
                $e[$f] = __('partner_apply.errors.required');
            }
        }
        if ($n['niu'] !== null && ! preg_match(BrokerOnboardingTarget::NIU_PATTERN, $n['niu'])) {
            $e['niu'] = __('bulk_onboarding.errors.niu_format');
        }
        if ($n['rccm'] !== null && ! preg_match(BrokerOnboardingTarget::RCCM_PATTERN, $n['rccm'])) {
            $e['rccm'] = __('bulk_onboarding.errors.rccm_format');
        }
        if ($n['licence_number'] !== null && (! preg_match(BrokerOnboardingTarget::LICENCE_PATTERN, $n['licence_number']) || ! preg_match('/\d/', $n['licence_number']))) {
            $e['licence_number'] = __('bulk_onboarding.errors.licence_format');
        }
        if ($n['licence_expires_on'] !== null) {
            $d = rescue(fn () => CarbonImmutable::createFromFormat('!Y-m-d', (string) $n['licence_expires_on']), null, false);
            if (! $d instanceof CarbonImmutable || $d->lt(CarbonImmutable::today())) {
                $e['licence_expires_on'] = __('partner_apply.errors.licence_expired');
            } else {
                $n['licence_expires_on'] = $d->toDateString();
            }
        }
        if (($data['applicant_phone'] ?? null) && $n['applicant_phone'] === null) {
            $e['applicant_phone'] = __('partner_apply.errors.phone');
        }
        if (($data['org_phone'] ?? null) && $n['org_phone'] === null) {
            $e['org_phone'] = __('partner_apply.errors.phone');
        }
        if ($type === 'AGENT' && ! ($data['independent'] ?? false) && ($n['brokerage_tenant_id'] === null || ! array_key_exists($n['brokerage_tenant_id'], $this->brokerages()))) {
            $e['brokerage_tenant_id'] = __('partner_apply.errors.brokerage');
        }
        if ($e) {
            throw ValidationException::withMessages($e);
        }

        return $n;
    }

    /**
     * Stores a new UNVERIFIED application and sends the verification code.
     *
     * @param  array<string, UploadedFile|null>  $files
     * @return array{application: PartnerApplication, token: string}
     */
    public function submit(array $data, array $files, ?string $ip): array
    {
        $n = $this->normalise($data);
        if ($reason = $this->hardDuplicate($n)) {
            // Generic wording: the public form never reveals which organisation or record matched.
            throw ValidationException::withMessages(['legal_name' => __('partner_apply.errors.'.$reason)]);
        }
        [$token, $hash] = PublicIntake::newToken();

        $application = DB::transaction(function () use ($n, $files, $ip, $hash) {
            $a = PartnerApplication::create([
                'reference' => PublicIntake::reference('PA'), 'type' => $n['type'], 'status' => 'UNVERIFIED', 'legal_name' => $n['legal_name'],
                'trade_name' => $n['trade_name'], 'rccm' => $n['rccm'], 'niu' => $n['niu'], 'licence_number' => $n['licence_number'],
                'licence_expires_on' => $n['licence_expires_on'], 'brokerage_tenant_id' => $n['brokerage_tenant_id'], 'city' => $n['city'], 'address' => $n['address'],
                'applicant_name' => $n['applicant_name'], 'applicant_phone' => $n['applicant_phone'], 'applicant_email' => $n['applicant_email'],
                'locale' => $n['locale'], 'duplicate_flags' => $this->softFlags($n), 'status_token_hash' => $hash, 'ip_hash' => PublicIntake::ipHash($ip),
                'result' => array_filter(['org_phone' => $n['org_phone'], 'org_email' => $n['org_email']]),
            ]);
            $a->forceFill(['documents' => $this->intake->storeDocuments($files, 'partner-applications/'.$a->id, 'PARTNER_APPLICATION')])->save();
            $this->audit->record('partner_application.created', 'partner_application', $a->id, ['type' => $a->type, 'reference' => $a->reference]);

            return $a;
        });
        $this->sendCode($application);

        return ['application' => $application, 'token' => $token];
    }

    public function findByToken(string $token): ?PartnerApplication
    {
        return PartnerApplication::where('status_token_hash', PublicIntake::hash($token))->first();
    }

    public function sendCode(PartnerApplication $a): void
    {
        $destination = $a->applicant_phone && $this->intake->smsAvailable() ? $a->applicant_phone : $a->applicant_email;
        $this->intake->sendCode($a, $destination, __('partner_apply.code_message', [], $a->locale), __('partner_apply.mail.code.subject', [], $a->locale));
    }

    public function verify(PartnerApplication $a, string $code): bool
    {
        if ($a->status !== 'UNVERIFIED') {
            return true;
        }
        if (! $this->intake->checkCode($a, $code)) {
            $this->audit->record('partner_application.code_failed', 'partner_application', $a->id, ['attempts' => $a->code_attempts]);

            return false;
        }
        $this->review->transition($a, 'SUBMITTED', [], 'submitted', ['channel' => $a->verification_channel]);
        $this->review->notify($a, 'received', []);
        if ($a->type === 'AGENT' && $a->brokerage_tenant_id) {
            $this->askBrokerage($a);
        }

        return true;
    }

    /** Emails the brokerage's administrators a single-use link to confirm (or decline) that the agent works for them. */
    private function askBrokerage(PartnerApplication $a): void
    {
        [$token, $hash] = PublicIntake::newToken();
        $a->forceFill(['brokerage_confirmation_hash' => $hash])->save();
        $emails = DB::table('tenant_memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')->where('m.tenant_id', $a->brokerage_tenant_id)
            ->where('m.status', 'ACTIVE')->where('m.role_code', 'BROKER_ADMIN')->whereNotNull('u.email')->pluck('u.email')
            ->merge(DB::table('tenant_branches')->where('tenant_id', $a->brokerage_tenant_id)->where('code', 'HQ')->whereNotNull('email')->pluck('email'))
            ->map(fn ($e) => mb_strtolower((string) $e))->unique()->values();
        $url = url('/partners/apply/brokerage/'.$token);
        foreach ($emails as $email) {
            $this->intake->mail($email, __('partner_apply.mail.brokerage.subject', ['agent' => $a->applicant_name], $a->locale),
                __('partner_apply.mail.brokerage.body', ['agent' => $a->applicant_name, 'reference' => $a->reference, 'url' => $url], $a->locale));
        }
        $this->audit->record('partner_application.brokerage_asked', 'partner_application', $a->id, ['brokerage_tenant_id' => $a->brokerage_tenant_id, 'recipients' => $emails->count()]);
    }

    public function findByBrokerageToken(string $token): ?PartnerApplication
    {
        return PartnerApplication::where('brokerage_confirmation_hash', PublicIntake::hash($token))->first();
    }

    /** Brokerage answer (single use). Declining lets the admins reject or ask the agent to apply as independent. */
    public function brokerageAnswer(PartnerApplication $a, bool $confirm, ?User $admin = null, ?string $note = null): void
    {
        if ($admin !== null) {
            $this->review->authorize($admin);
        }
        abort_if($a->brokerage_confirmed_at !== null || $a->brokerage_declined_at !== null || ! in_array($a->status, IntakeReview::OPEN, true), 410);
        $a->forceFill([$confirm ? 'brokerage_confirmed_at' : 'brokerage_declined_at' => now(), 'brokerage_confirmation_hash' => null])->save();
        $this->audit->record('partner_application.brokerage_'.($confirm ? 'confirmed' : 'declined'), 'partner_application', $a->id,
            ['brokerage_tenant_id' => $a->brokerage_tenant_id, 'by' => $admin ? 'PLATFORM_ADMIN' : 'BROKERAGE_LINK', 'note' => $note]);
    }

    /** @param array<string, UploadedFile|null> $files */
    public function respond(PartnerApplication $a, string $response, array $files): void
    {
        if ($a->status !== 'INFO_REQUESTED') {
            throw ValidationException::withMessages(['response' => __('partner_apply.errors.wrong_status', ['status' => $a->status])]);
        }
        $docs = $this->intake->storeDocuments($files, 'partner-applications/'.$a->id, 'PARTNER_APPLICATION');
        $this->review->respond($a, $response, $docs);
    }

    /** Final decision by the checker: provisions the organisation and sends the admin invitation. */
    public function approve(PartnerApplication $a, User $checker, ?string $note = null): PartnerApplication
    {
        $this->review->assertChecker($a, $checker);
        if ($a->recommendation !== 'APPROVE') {
            throw ValidationException::withMessages(['recommendation' => __('partner_apply.errors.recommended_reject')]);
        }
        if (! $this->intake->documentsClean($a->documents ?? [])) {
            throw ValidationException::withMessages(['documents' => __('partner_apply.errors.documents_not_clean')]);
        }
        if ($a->type === 'AGENT' && $a->brokerage_tenant_id && $a->brokerage_confirmed_at === null) {
            throw ValidationException::withMessages(['brokerage' => __('partner_apply.errors.brokerage_unconfirmed')]);
        }
        $n = $this->normalisedOf($a);
        if ($reason = $this->hardDuplicate($n, $a->id)) {
            throw ValidationException::withMessages(['legal_name' => __('partner_apply.errors.'.$reason)]);
        }

        DB::transaction(function () use ($a, $checker, $note, $n) {
            $result = match ($a->type) {
                'BROKER' => $this->provisionBroker($a, $n, $checker),
                'INSURER' => $this->provisionInsurer($a, $n, $checker),
                'AGENT' => $this->provisionAgent($a, $n, $checker),
            };
            $this->review->markApproved($a, $checker, $result, $note);
        });

        return $a->refresh();
    }

    private function provisionBroker(PartnerApplication $a, array $n, User $actor): array
    {
        $target = app(BrokerOnboardingTarget::class);
        $row = [
            'legal_name' => $n['legal_name'], 'trade_name' => $n['trade_name'], 'rccm' => $n['rccm'], 'niu' => $n['niu'], 'licence_number' => $n['licence_number'],
            'licence_expires_on' => $n['licence_expires_on'], 'city' => $n['city'], 'address' => $n['address'], 'phone' => $n['org_phone'], 'email' => $n['org_email'],
            'admin_name' => $n['applicant_name'], 'admin_phone' => $n['applicant_phone'], 'admin_email' => $n['applicant_email'], 'branches' => null, 'locale' => $n['locale'],
        ];
        $seen = [];
        $check = $target->check($row, [], $seen);
        if ($check['status'] !== 'NEW') {
            throw ValidationException::withMessages(['legal_name' => (string) ($check['matches'] ?? $check['error'] ?? __('partner_apply.errors.duplicate'))]);
        }
        $tenantId = $target->import($row, [], $actor, 'partner_application:'.$a->id);
        $tenant = Tenant::findOrFail($tenantId);
        $tenant->update(['settings' => ['onboarding' => ['source' => 'partner_application', 'application_id' => $a->id]] + ($tenant->settings ?? [])]);
        $this->attachEvidence($a, $target->outcome()['partner_id'] ?? null);

        return collect($target->outcome())->except('token_encrypted')->all();
    }

    private function provisionInsurer(PartnerApplication $a, array $n, User $actor): array
    {
        $tenant = $this->newTenant('CARRIER', $n, $a, $actor);
        $party = $this->parties->create(['type' => 'ORGANIZATION', 'display_name' => $n['trade_name'] ?? $n['legal_name'], 'registration_number' => $n['rccm'],
            'phone_e164' => $n['org_phone'], 'email' => $n['org_email'], 'identifier_type' => 'NIU', 'identifier_value' => $n['niu']]);
        $this->parties->addIdentifier($party, 'RCCM', $n['rccm']);
        $carrier = Carrier::create(['party_id' => $party->id, 'cima_code' => $n['licence_number'] ?? $a->reference, 'status' => 'PENDING', 'legal_name' => mb_strtoupper($n['legal_name']),
            'trade_name' => $n['trade_name'] ?? $n['legal_name'], 'country_code' => 'CM', 'is_official_register' => false, 'capabilities' => []]);
        $this->audit->record('carrier.created', 'carrier', $carrier->id, ['source' => 'partner_application', 'application_id' => $a->id]);
        $tenant->update(['settings' => ['carrier_id' => $carrier->id] + ($tenant->settings ?? [])]);
        $partner = Partner::create(['tenant_id' => $tenant->id, 'party_id' => $party->id, 'type' => 'CARRIER', 'status' => 'PENDING',
            'compliance' => ['source' => 'partner_application', 'carrier_id' => $carrier->id], 'legal_name' => mb_strtoupper($n['legal_name']),
            'trade_name' => $n['trade_name'] ?? $n['legal_name'], 'country_code' => 'CM']);
        $this->audit->record('partner.created', 'partner', $partner->id, ['type' => 'CARRIER', 'source' => 'partner_application']);
        $licence = $n['licence_number'] ? $this->licensing->submit($partner, ['authority' => 'CIMA', 'licence_type' => 'CARRIER_AUTHORIZATION',
            'licence_number' => $n['licence_number'], 'expires_on' => $n['licence_expires_on']]) : null;
        $branch = $this->headOffice($tenant, $n);
        $invitation = $this->invite($tenant, $actor, $n, 'CARRIER_SUPER_ADMIN', $carrier->id, preferEmail: true);
        $this->attachEvidence($a, $partner->id);

        return ['tenant_id' => $tenant->id, 'carrier_id' => $carrier->id, 'partner_id' => $partner->id, 'party_id' => $party->id,
            'licence_id' => $licence?->id, 'branch_ids' => [$branch->id], 'invitation_id' => $invitation];
    }

    private function provisionAgent(PartnerApplication $a, array $n, User $actor): array
    {
        $independent = $a->brokerage_tenant_id === null;
        $tenant = $independent ? $this->newTenant('AGENCY', $n, $a, $actor) : Tenant::findOrFail($a->brokerage_tenant_id);
        $branchIds = $independent ? [$this->headOffice($tenant, $n)->id] : [];

        $party = $n['applicant_phone'] ? Party::whereHas('contacts', fn ($q) => $q->where(['type' => 'PHONE', 'normalized_value' => $n['applicant_phone']]))->first() : null;
        if ($party && Partner::where('party_id', $party->id)->exists()) {
            throw ValidationException::withMessages(['legal_name' => __('partner_apply.errors.duplicate')]);
        }
        $party ??= $this->parties->create(['type' => 'INDIVIDUAL', 'display_name' => $n['applicant_name'], 'phone_e164' => $n['applicant_phone'],
            'email' => DB::table('party_contacts')->where(['type' => 'EMAIL', 'normalized_value' => $n['applicant_email']])->exists() ? null : $n['applicant_email']]);
        if (! DB::table('party_identifiers')->where(['type' => 'NIU', 'country_code' => 'CM', 'value_hash' => BrokerOnboardingTarget::identifierHash('NIU', $n['niu'])])->exists()) {
            $this->parties->addIdentifier($party, 'NIU', $n['niu']);
        }
        $partner = Partner::create(['tenant_id' => $tenant->id, 'party_id' => $party->id, 'type' => 'AGENT', 'status' => 'PENDING',
            'compliance' => ['source' => 'partner_application', 'application_id' => $a->id], 'legal_name' => mb_strtoupper($n['legal_name']),
            'trade_name' => $n['trade_name'] ?? $n['legal_name'], 'country_code' => 'CM']);
        $this->audit->record('partner.created', 'partner', $partner->id, ['type' => 'AGENT', 'source' => 'partner_application', 'independent' => $independent]);
        $this->hierarchy->place($partner, ['agent_type' => $independent ? 'INDEPENDENT' : 'EMPLOYEE'], 'Partner application '.$a->reference, $actor);
        $licence = $this->licensing->submit($partner, ['authority' => BrokerOnboardingTarget::AUTHORITY, 'licence_type' => 'AGENT_MANDATE',
            'licence_number' => $n['licence_number'], 'expires_on' => $n['licence_expires_on']]);
        $invitation = $this->invite($tenant, $actor, $n, 'AGENT', null, preferEmail: false);
        $this->attachEvidence($a, $partner->id);

        return ['tenant_id' => $tenant->id, 'partner_id' => $partner->id, 'party_id' => $party->id, 'licence_id' => $licence->id,
            'branch_ids' => $branchIds, 'invitation_id' => $invitation, 'independent' => $independent];
    }

    private function newTenant(string $type, array $n, PartnerApplication $a, User $actor): Tenant
    {
        return $this->provisioner->tenant($type, $n, ['source' => 'partner_application', 'application_id' => $a->id], 'Partner application '.$a->reference, $actor);
    }

    private function headOffice(Tenant $tenant, array $n): TenantBranch
    {
        return $this->provisioner->headOffice($tenant, $n, 'partner_application');
    }

    private function invite(Tenant $tenant, User $actor, array $n, string $role, ?string $carrierId, bool $preferEmail): string
    {
        $useEmail = $preferEmail || $n['applicant_phone'] === null;
        $issued = $this->invitations->issue($tenant, $actor, $useEmail ? $n['applicant_email'] : null, $useEmail ? null : $n['applicant_phone'],
            $role, BrokerOnboardingTarget::INVITATION_TTL_HOURS, $carrierId);

        return $issued['invitation']->id;
    }

    /** The reviewed uploads become evidence of the new partner (same documents, re-homed to the partner's tenant). */
    private function attachEvidence(PartnerApplication $a, ?string $partnerId): void
    {
        $tenantId = $partnerId ? Partner::whereKey($partnerId)->value('tenant_id') : null;
        $ids = array_column($a->documents ?? [], 'document_id');
        if ($tenantId && $ids) {
            DB::table('documents')->whereIn('id', $ids)->update(['tenant_id' => $tenantId, 'party_id' => Partner::whereKey($partnerId)->value('party_id'), 'updated_at' => now()]);
            if ($licenceDoc = collect($a->documents)->firstWhere('kind', 'licence')) {
                DB::table('partner_licences')->where('partner_id', $partnerId)->whereNull('evidence_document_id')->update(['evidence_document_id' => $licenceDoc['document_id']]);
            }
        }
    }

    private function normalisedOf(PartnerApplication $a): array
    {
        return ['type' => $a->type, 'legal_name' => $a->legal_name, 'trade_name' => $a->trade_name, 'city' => $a->city, 'address' => $a->address, 'rccm' => $a->rccm,
            'niu' => $a->niu, 'licence_number' => $a->licence_number, 'licence_expires_on' => $a->licence_expires_on?->toDateString(),
            'applicant_name' => $a->applicant_name, 'applicant_email' => $a->applicant_email, 'applicant_phone' => $a->applicant_phone,
            'org_phone' => $a->result['org_phone'] ?? null, 'org_email' => $a->result['org_email'] ?? null, 'brokerage_tenant_id' => $a->brokerage_tenant_id, 'locale' => $a->locale];
    }

    /**
     * Reason key (partner_apply.errors.*) when this organisation is already on the platform, in the official register,
     * or has an application in progress; null otherwise.
     */
    public function hardDuplicate(array $n, ?string $exceptId = null): ?string
    {
        $ids = array_filter(['rccm' => $n['rccm'], 'niu' => $n['type'] === 'AGENT' ? null : $n['niu']]);
        $tenant = Tenant::withTrashed()->where(function ($q) use ($n, $ids) {
            $q->whereRaw('lower(legal_name) = ?', [mb_strtolower((string) $n['legal_name'])]);
            isset($ids['rccm']) && $q->orWhere('registration_number', $ids['rccm']);
            isset($ids['niu']) && $q->orWhere('tax_number', $ids['niu']);
        })->exists();
        if ($tenant) {
            return 'duplicate';
        }
        foreach (['NIU' => $n['niu'], 'RCCM' => $n['rccm']] as $type => $v) {
            if ($v !== null && DB::table('party_identifiers')->where(['type' => $type, 'country_code' => 'CM', 'value_hash' => BrokerOnboardingTarget::identifierHash($type, $v)])->exists()) {
                return 'duplicate';
            }
        }
        if ($n['licence_number'] !== null && (DB::table('partner_licences')->whereRaw('upper(licence_number) = ?', [$n['licence_number']])->exists()
            || DB::table('partners')->whereRaw('upper(licence_number) = ?', [$n['licence_number']])->exists()
            || DB::table('carriers')->whereRaw('upper(cima_code) = ?', [$n['licence_number']])->exists())) {
            return 'duplicate';
        }
        // Official register (DGTCFM/MINFI): the organisation is listed — it should be claimed, not re-created.
        if ($n['type'] !== 'AGENT') {
            $names = array_values(array_filter([BrokerOnboardingTarget::norm($n['legal_name']), BrokerOnboardingTarget::norm($n['trade_name'])]));
            $inRegister = fn ($rows) => $rows->contains(fn ($r) => array_intersect($names, array_filter([BrokerOnboardingTarget::norm($r->legal_name), BrokerOnboardingTarget::norm($r->trade_name)])) !== []);
            if ($inRegister(DB::table('carriers')->where('is_official_register', true)->get(['legal_name', 'trade_name']))
                || $inRegister(DB::table('partners')->where('is_official_register', true)->get(['legal_name', 'trade_name']))) {
                return 'in_register';
            }
        }
        $pending = PartnerApplication::query()->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->where(fn ($q) => $q->whereIn('status', ['SUBMITTED', 'UNDER_REVIEW', 'INFO_REQUESTED'])->orWhere(fn ($w) => $w->where('status', 'UNVERIFIED')->where('created_at', '>=', now()->subDay())))
            ->where(function ($q) use ($n) {
                $q->where('applicant_email', $n['applicant_email']);
                $n['niu'] && $q->orWhere('niu', $n['niu']);
                $n['rccm'] && $q->orWhere('rccm', $n['rccm']);
                $n['licence_number'] && $q->orWhere('licence_number', $n['licence_number']);
            })->exists();

        return $pending ? 'pending' : null;
    }

    /** Non-blocking signals for the reviewer (the applicant never sees them). @return list<string> */
    private function softFlags(array $n): array
    {
        $flags = [];
        foreach (['applicant_phone' => ['PHONE', $n['applicant_phone']], 'applicant_email' => ['EMAIL', $n['applicant_email']], 'org_phone' => ['PHONE', $n['org_phone']], 'org_email' => ['EMAIL', $n['org_email']]] as $field => [$type, $v]) {
            if ($v !== null && ($party = DB::table('party_contacts')->where(['type' => $type, 'normalized_value' => $v])->value('party_id'))) {
                $partner = DB::table('partners')->where('party_id', $party)->first(['type', 'legal_name']);
                $flags[] = $partner ? "{$field}: contact of existing {$partner->type} partner {$partner->legal_name}" : "{$field}: contact already known (existing party)";
            }
        }
        $similar = Tenant::query()->whereRaw('lower(legal_name) like ?', ['%'.mb_strtolower(Str::limit((string) $n['legal_name'], 12, '')).'%'])->limit(3)->pluck('legal_name');
        foreach ($similar as $name) {
            $flags[] = "similar tenant name: {$name}";
        }

        return $flags;
    }
}
