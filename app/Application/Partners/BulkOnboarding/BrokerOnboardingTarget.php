<?php

declare(strict_types=1);

namespace App\Application\Partners\BulkOnboarding;

use App\Application\Audit\AuditWriter;
use App\Application\Customers\PartyService;
use App\Application\Identity\InvitationService;
use App\Application\Import\PartialImportTarget;
use App\Application\Partners\LicensingService;
use App\Application\Tenancy\TenantLifecycleService;
use App\Models\Party;
use App\Models\PartyContact;
use App\Models\Partner;
use App\Models\Tenant;
use App\Models\TenantBranch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * S9 — bulk broker onboarding: one row = one brokerage. Runs on the generic ImportPipeline (upload → validate →
 * preview → submit → approve by another admin → import → audit), so the batch record, maker-checker and audit
 * trail are the pipeline's. For each NEW row, through the existing services:
 *   BROKER tenant (activated through TenantLifecycleService) → ORGANIZATION party (PartyService, NIU + RCCM identifiers)
 *   → BROKER partner (or the matching official-register partner, linked to the new tenant) → licence
 *   (LicensingService, PENDING_VERIFICATION) → head office + listed branches → BROKER_ADMIN invitation
 *   (InvitationService) delivered by SMS (SmsGateway) and/or email.
 * Idempotent: a brokerage already on the platform (NIU, RCCM, licence, name) is a DUPLICATE and is skipped.
 */
final class BrokerOnboardingTarget implements PartialImportTarget
{
    public const KEY = 'broker_onboarding';

    public const AUTHORITY = 'MINFI';

    public const INVITATION_TTL_HOURS = 168;

    /** Template column order (also the CSV/XLSX header). */
    public const FIELDS = [
        'legal_name' => true, 'trade_name' => false, 'rccm' => true, 'niu' => true, 'licence_number' => true, 'licence_expires_on' => true,
        'city' => true, 'address' => false, 'phone' => true, 'email' => false,
        'admin_name' => true, 'admin_phone' => false, 'admin_email' => false, 'branches' => false, 'locale' => false,
    ];

    private array $outcome = [];

    public function __construct(
        private readonly PartyService $parties,
        private readonly LicensingService $licensing,
        private readonly InvitationService $invitations,
        private readonly TenantLifecycleService $lifecycle,
        private readonly InvitationDelivery $delivery,
        private readonly AuditWriter $audit,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return __('bulk_onboarding.target_label');
    }

    public function fields(): array
    {
        return self::FIELDS;
    }

    public function params(array $params): array
    {
        return [];
    }

    public function outcome(): array
    {
        return $this->outcome;
    }

    /** Normalises one row; returns [normalised row, list of error messages]. */
    public static function normalise(array $row): array
    {
        $e = [];
        $s = fn (string $k) => ($v = trim((string) ($row[$k] ?? ''))) === '' ? null : preg_replace('/\s+/u', ' ', $v);
        $n = [
            'legal_name' => $s('legal_name'), 'trade_name' => $s('trade_name'), 'city' => $s('city'), 'address' => $s('address'),
            'admin_name' => $s('admin_name'), 'branches' => $s('branches'),
            'rccm' => ($v = $s('rccm')) ? mb_strtoupper(str_replace(' ', '', $v)) : null,
            'niu' => ($v = $s('niu')) ? mb_strtoupper(preg_replace('/[\s\-]+/', '', $v)) : null,
            'licence_number' => ($v = $s('licence_number')) ? mb_strtoupper($v) : null,
            'locale' => in_array($l = strtolower((string) $s('locale')), ['en', 'fr'], true) ? $l : 'fr',
        ];
        foreach (['legal_name', 'rccm', 'niu', 'licence_number', 'city', 'admin_name'] as $req) {
            if ($n[$req] === null) {
                $e[] = __('bulk_onboarding.errors.required', ['field' => __('bulk_onboarding.fields.'.$req)]);
            }
        }
        if ($n['niu'] !== null && ! preg_match('/^[A-Z]\d{12}[A-Z]$/', $n['niu'])) {
            $e[] = __('bulk_onboarding.errors.niu_format');
        }
        if ($n['rccm'] !== null && ! preg_match('#^(RC|CM)[A-Z0-9/\-]{6,38}$#', $n['rccm'])) {
            $e[] = __('bulk_onboarding.errors.rccm_format');
        }
        if ($n['licence_number'] !== null && (! preg_match('#^[A-Z0-9][A-Z0-9/\-. ]{2,39}$#', $n['licence_number']) || ! preg_match('/\d/', $n['licence_number']))) {
            $e[] = __('bulk_onboarding.errors.licence_format');
        }
        $n['licence_expires_on'] = null;
        if (($raw = $s('licence_expires_on')) === null) {
            $e[] = __('bulk_onboarding.errors.required', ['field' => __('bulk_onboarding.fields.licence_expires_on')]);
        } elseif (($date = self::date($raw)) === null) {
            $e[] = __('bulk_onboarding.errors.date_format');
        } elseif ($date->lt(CarbonImmutable::today())) {
            $e[] = __('bulk_onboarding.errors.licence_expired', ['date' => $date->toDateString()]);
        } else {
            $n['licence_expires_on'] = $date->toDateString();
        }
        foreach (['phone' => true, 'admin_phone' => false] as $k => $required) {
            $raw = $s($k);
            $n[$k] = $raw === null ? null : self::phone($raw);
            if ($raw === null && $required) {
                $e[] = __('bulk_onboarding.errors.required', ['field' => __('bulk_onboarding.fields.'.$k)]);
            } elseif ($raw !== null && $n[$k] === null) {
                $e[] = __('bulk_onboarding.errors.phone_format', ['field' => __('bulk_onboarding.fields.'.$k)]);
            }
        }
        foreach (['email', 'admin_email'] as $k) {
            $raw = $s($k);
            $n[$k] = $raw === null ? null : mb_strtolower($raw);
            if ($raw !== null && filter_var($n[$k], FILTER_VALIDATE_EMAIL) === false) {
                $e[] = __('bulk_onboarding.errors.email_format', ['field' => __('bulk_onboarding.fields.'.$k)]);
            }
        }
        if ($s('admin_phone') === null && $s('admin_email') === null) {
            $e[] = __('bulk_onboarding.errors.admin_contact');
        }
        $n['branch_list'] = self::branches($n['branches']);

        return [$n, $e];
    }

    /** E.164 Cameroon: +237 then 9 digits starting with 2 (fixed) or 6 (mobile). Accepts local, 237… and 00237… forms. */
    public static function phone(string $raw): ?string
    {
        $d = preg_replace('/[^\d+]/', '', $raw) ?? '';
        $d = match (true) {
            str_starts_with($d, '+') => $d,
            str_starts_with($d, '00') => '+'.substr($d, 2),
            str_starts_with($d, '237') && strlen($d) === 12 => '+'.$d,
            (bool) preg_match('/^\d{9}$/', $d) => '+237'.$d,
            default => $d,
        };

        return preg_match('/^\+237[26]\d{8}$/', $d) ? $d : null;
    }

    private static function date(string $raw): ?CarbonImmutable
    {
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y'] as $f) {
            $d = rescue(fn () => CarbonImmutable::createFromFormat('!'.$f, $raw), null, false);
            if ($d instanceof CarbonImmutable && $d->format($f) === $raw) {
                return $d;
            }
        }
        if (is_numeric($raw) && (int) $raw > 20000 && (int) $raw < 80000) { // Excel serial date
            return CarbonImmutable::create(1899, 12, 30)->addDays((int) $raw);
        }

        return null;
    }

    /** "Name@City|Name 2@City 2" (city optional) → [[name, city], …] */
    public static function branches(?string $raw): array
    {
        if ($raw === null) {
            return [];
        }

        return collect(preg_split('/[|;\n]/', $raw))->map(fn ($b) => array_map('trim', explode('@', $b, 2) + [1 => null]))
            ->filter(fn ($b) => $b[0] !== '')->map(fn ($b) => ['name' => mb_substr($b[0], 0, 160), 'city' => $b[1] ?: null])->values()->all();
    }

    public static function norm(?string $name): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower(Str::ascii((string) $name))) ?? '');
    }

    public static function identifierHash(string $type, string $value): string
    {
        return hash_hmac('sha256', 'CM|'.$type.'|'.mb_strtoupper(preg_replace('/[\s\-]+/', '', $value)), (string) config('app.key'));
    }

    public function check(array $row, array $params, array &$seen): array
    {
        $line = $seen['__row'] = ($seen['__row'] ?? 0) + 1;
        [$n, $errors] = self::normalise($row);
        $key = $n['niu'] ?? $n['rccm'] ?? $n['legal_name'] ?? '?';
        if ($errors) {
            return ['status' => 'ERROR', 'key' => $key, 'error' => implode(' ', $errors)];
        }

        if ($match = $this->existing($n)) {
            return ['status' => 'DUPLICATE', 'key' => $key, 'matches' => $match];
        }

        // Repeats inside the file (company and admin contacts share one bucket: the same number twice in one row is fine).
        $values = [];
        foreach (['niu' => $n['niu'], 'rccm' => $n['rccm'], 'licence_number' => $n['licence_number'], 'legal_name' => self::norm($n['legal_name']),
            'phone' => $n['phone'], 'email' => $n['email'], 'admin_phone' => $n['admin_phone'], 'admin_email' => $n['admin_email']] as $f => $v) {
            if ($v !== null && $v !== '') {
                $values[str_replace('admin_', '', $f).':'.$v] ??= $f;
            }
        }
        foreach ($values as $k => $f) {
            if (isset($seen[$k])) {
                return ['status' => 'ERROR', 'key' => $key, 'error' => __('bulk_onboarding.errors.repeated', ['field' => __('bulk_onboarding.fields.'.$f), 'row' => $seen[$k]])];
            }
        }
        foreach (array_keys($values) as $k) {
            $seen[$k] = $line;
        }

        $register = $this->registerPartner($n);

        return ['status' => 'NEW', 'key' => $key] + ($register ? ['register_partner_id' => $register->id] : []);
    }

    /** Why this brokerage is already on the platform, or null. */
    private function existing(array $n): ?string
    {
        $tenant = Tenant::withTrashed()->where(fn ($q) => $q->where('registration_number', $n['rccm'])->orWhere('tax_number', $n['niu'])
            ->orWhereRaw('lower(legal_name) = ?', [mb_strtolower($n['legal_name'])]))->first();
        if ($tenant) {
            return __('bulk_onboarding.matches.tenant', ['name' => $tenant->legal_name]);
        }
        foreach (['NIU' => $n['niu'], 'RCCM' => $n['rccm']] as $type => $v) {
            if (DB::table('party_identifiers')->where(['type' => $type, 'country_code' => 'CM', 'value_hash' => self::identifierHash($type, $v)])->exists()) {
                return __('bulk_onboarding.matches.identifier', ['type' => $type]);
            }
        }
        if (DB::table('partner_licences')->whereRaw('upper(licence_number) = ?', [$n['licence_number']])->exists()
            || DB::table('partners')->whereRaw('upper(licence_number) = ?', [$n['licence_number']])->exists()) {
            return __('bulk_onboarding.matches.licence', ['number' => $n['licence_number']]);
        }
        $names = array_filter([self::norm($n['legal_name']), self::norm($n['trade_name'])]);
        $partner = Partner::with('party')->where('type', 'BROKER')->where(fn ($q) => $q->whereNotNull('tenant_id')->orWhere('is_official_register', false))->get()
            ->first(fn (Partner $p) => array_intersect($names, array_filter([self::norm($p->legal_name), self::norm($p->trade_name), self::norm($p->party?->display_name)])) !== []);
        if ($partner) {
            return __('bulk_onboarding.matches.partner', ['name' => $partner->legal_name ?? $partner->party?->display_name]);
        }
        foreach (['PHONE' => $n['phone'], 'EMAIL' => $n['email']] as $type => $v) {
            if ($v !== null && DB::table('party_contacts')->where(['type' => $type, 'normalized_value' => $v])->exists()) {
                return __('bulk_onboarding.matches.contact', ['value' => $v]);
            }
        }

        return null;
    }

    /** The official DGTCFM/MINFI register row of this broker, not yet linked to a tenant. */
    private function registerPartner(array $n): ?Partner
    {
        $names = array_filter([self::norm($n['legal_name']), self::norm($n['trade_name'])]);

        return Partner::with('party')->where('type', 'BROKER')->where('is_official_register', true)->whereNull('tenant_id')->get()
            ->first(fn (Partner $p) => array_intersect($names, array_filter([self::norm($p->legal_name), self::norm($p->trade_name), self::norm($p->party?->display_name)])) !== []);
    }

    public function import(array $row, array $params, ?User $actor, string $batchId): string
    {
        if (! $actor) {
            throw ValidationException::withMessages(['actor' => 'An approver is required.']);
        }
        [$n] = self::normalise($row);
        $this->outcome = [];

        // 1. BROKER tenant, activated through the lifecycle service (status history + audit).
        $tenant = Tenant::create([
            'type' => 'BROKER', 'legal_name' => $n['legal_name'], 'trade_name' => $n['trade_name'], 'slug' => $this->slug($n['trade_name'] ?? $n['legal_name']),
            'registration_number' => $n['rccm'], 'tax_number' => $n['niu'], 'status' => 'PENDING', 'country_code' => 'CM', 'currency' => 'XAF',
            'primary_locale' => $n['locale'], 'timezone' => 'Africa/Douala',
            'settings' => ['city' => $n['city'], 'address' => $n['address'], 'onboarding' => ['source' => self::KEY, 'import_batch_id' => $batchId]],
        ]);
        $this->audit->record('tenant.created', 'tenant', $tenant->id, ['type' => 'BROKER', 'source' => self::KEY, 'import_batch_id' => $batchId]);
        $this->lifecycle->transition($tenant, 'ACTIVE', 'BULK_ONBOARDING', 'Bulk broker onboarding batch '.$batchId, $actor);

        // 2. Party + partner: the official-register broker when it matches, otherwise a new one.
        $partner = $this->registerPartner($n);
        $linked = $partner !== null;
        if ($partner) {
            $party = $partner->party;
            foreach (['PHONE' => $n['phone'], 'EMAIL' => $n['email']] as $type => $v) {
                if ($v !== null && ! DB::table('party_contacts')->where(['type' => $type, 'normalized_value' => $v])->exists()) {
                    PartyContact::create(['party_id' => $party->id, 'type' => $type, 'normalized_value' => $v, 'is_primary' => true]);
                }
            }
            $this->parties->addIdentifier($party, 'NIU', $n['niu']);
            $partner->update(['tenant_id' => $tenant->id]);
            $this->audit->record('partner.linked', 'partner', $partner->id, ['tenant_id' => $tenant->id, 'source' => self::KEY, 'official_register' => true]);
        } else {
            $party = $this->parties->create(['type' => 'ORGANIZATION', 'display_name' => $n['trade_name'] ?? $n['legal_name'], 'registration_number' => $n['rccm'],
                'phone_e164' => $n['phone'], 'email' => $n['email'], 'identifier_type' => 'NIU', 'identifier_value' => $n['niu']]);
            $partner = Partner::create(['tenant_id' => $tenant->id, 'party_id' => $party->id, 'type' => 'BROKER', 'status' => 'PENDING', 'compliance' => ['source' => self::KEY],
                'legal_name' => mb_strtoupper($n['legal_name']), 'trade_name' => $n['trade_name'] ?? $n['legal_name'], 'country_code' => 'CM']);
            $this->audit->record('partner.created', 'partner', $partner->id, ['type' => 'BROKER', 'source' => self::KEY, 'import_batch_id' => $batchId]);
        }
        $this->parties->addIdentifier($party, 'RCCM', $n['rccm']);
        $party->update(['legal_identity' => array_filter(($party->legal_identity ?? []) + ['registration_number' => $n['rccm'], 'tax_number' => $n['niu'], 'country' => 'CM'])]);

        // 3. Licence (verified later by compliance; verification activates the partner).
        $licence = $this->licensing->submit($partner, ['authority' => self::AUTHORITY, 'licence_type' => 'BROKER', 'licence_number' => $n['licence_number'], 'expires_on' => $n['licence_expires_on']]);

        // 4. Head office + listed branches.
        $branches = [['name' => $n['locale'] === 'fr' ? 'Siège' : 'Head office', 'city' => $n['city'], 'address' => $n['address'], 'code' => 'HQ']];
        foreach ($n['branch_list'] as $i => $b) {
            $branches[] = ['name' => $b['name'], 'city' => $b['city'] ?? $n['city'], 'address' => null, 'code' => 'BR'.str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT)];
        }
        $branchIds = [];
        foreach ($branches as $b) {
            $branch = TenantBranch::create(['tenant_id' => $tenant->id, 'code' => $b['code'], 'name' => $b['name'], 'status' => 'ACTIVE', 'timezone' => 'Africa/Douala',
                'phone_e164' => $b['code'] === 'HQ' ? $n['phone'] : null, 'email' => $b['code'] === 'HQ' ? $n['email'] : null,
                'address' => array_filter(['city' => $b['city'], 'line1' => $b['address'], 'country' => 'CM'])]);
            $this->audit->record('tenant.branch.created', 'tenant_branch', $branch->id, ['source' => self::KEY]);
            $branchIds[] = $branch->id;
        }

        // 5. BROKER_ADMIN invitation (phone first: brokers sign in to the app with their phone).
        $issued = $this->invitations->issue($tenant, $actor, $n['admin_phone'] ? null : $n['admin_email'], $n['admin_phone'], 'BROKER_ADMIN', self::INVITATION_TTL_HOURS);
        $delivered = $this->delivery->send($n, $tenant, $issued['token']);

        $this->outcome = [
            'tenant_id' => $tenant->id, 'partner_id' => $partner->id, 'party_id' => $party->id, 'licence_id' => $licence->id, 'branch_ids' => $branchIds,
            'invitation_id' => $issued['invitation']->id, 'linked_register_partner' => $linked, 'delivery' => $delivered,
            // Undelivered codes are kept encrypted so the admin can pass them on from the result report.
            'token_encrypted' => collect($delivered)->contains('SENT') ? null : Crypt::encryptString($issued['token']),
        ];
        $this->audit->record('partner.bulk_onboarded', 'partner', $partner->id, ['tenant_id' => $tenant->id, 'import_batch_id' => $batchId, 'delivery' => $delivered]);

        return $tenant->id;
    }

    public function finish(array $params): void {}

    private function slug(string $name): string
    {
        $base = Str::limit(Str::slug($name) ?: 'broker', 70, '');
        $slug = $base;
        for ($i = 2; Tenant::withTrashed()->where('slug', $slug)->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }
}
