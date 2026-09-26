<?php

declare(strict_types=1);

namespace App\Application\Finance\ReferenceMasters;

use App\Application\Audit\AuditWriter;
use App\Application\Ledger\Posting\AccountingEventMappingService;
use App\Application\Ledger\Posting\DefaultChartOfAccounts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Agent GP6 — banks / payment institutions master, payment provider profiles, GL control-account mapping, event-to-GL view
 * and cost centres. Maker-checker on every configuration that feeds postings; CONFIG_REQUIRED values are gates, never defaults.
 */
final class FinanceReferenceService
{
    public function __construct(private readonly AuditWriter $audit, private readonly AccountingEventMappingService $mappings) {}

    // ------------------------------------------------------------------ institutions

    public function institutions(array $f = []): array
    {
        return DB::table('financial_institutions')
            ->when($f['type'] ?? null, fn ($q, $v) => $q->where('institution_type', $v))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('verification_status', $v))
            ->when($f['production_only'] ?? false, fn ($q) => $q->whereIn('verification_status', FinanceReferenceCatalogue::PRODUCTION_STATUSES))
            ->orderBy('institution_type')->orderBy('legal_name')->get()
            ->map(fn ($r) => $this->decorate($r))->all();
    }

    public function updateInstitution(string $id, array $d, string $actorId, string $reason): object
    {
        $row = DB::table('financial_institutions')->where('id', $id)->first() ?? abort(404);
        if (($d['verification_status'] ?? null) === 'VERIFIED_PUBLIC_SOURCE' && empty($d['source_url'] ?? $row->source_url)) {
            throw ValidationException::withMessages(['source_url' => 'A verified institution needs its public source URL.']);
        }
        if (! empty($d['bic_swift']) && ! preg_match('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/', $d['bic_swift'])) {
            throw ValidationException::withMessages(['bic_swift' => 'BIC/SWIFT must be 8 or 11 characters (ISO 9362).']);
        }
        $new = collect($d)->only(['legal_name', 'trade_name', 'bank_code', 'bic_swift', 'operating_status', 'head_office_city', 'website', 'source_url',
            'verification_status', 'effective_from', 'effective_until'])->all();
        foreach (['phones', 'branches', 'aliases'] as $j) {
            if (array_key_exists($j, $d)) {
                $new[$j] = json_encode(array_values((array) $d[$j]));
            }
        }
        DB::table('financial_institutions')->where('id', $id)->update($new + ['admin_edited_at' => now(), 'admin_edited_by' => $actorId, 'updated_at' => now()]);
        $this->audit->recordChange('finance.institution.updated', 'financial_institution', $id, (array) $row, $new, $reason);

        return $this->decorate(DB::table('financial_institutions')->find($id));
    }

    /** Import pipeline entry (Targets\FinancialInstitutionTarget). Never overwrites: an existing code/BIC is a duplicate. */
    public function importInstitution(array $row): string
    {
        $type = strtoupper((string) ($row['institution_type'] ?? 'BANK'));
        $id = (string) Str::uuid();
        DB::table('financial_institutions')->insert(['id' => $id, 'code' => $row['code'] ?? FinanceReferenceCatalogue::institutionCode($type, $row['legal_name']),
            'institution_type' => $type, 'legal_name' => $row['legal_name'], 'trade_name' => $row['trade_name'] ?? null, 'bank_code' => $row['bank_code'] ?? null,
            'bic_swift' => $row['bic_swift'] ?? null, 'head_office_city' => $row['head_office_city'] ?? null, 'website' => $row['website'] ?? null,
            'source' => 'OFFICIAL_IMPORT', 'source_url' => $row['source_url'] ?? null, 'verification_status' => strtoupper((string) ($row['verification_status'] ?? 'PENDING_VERIFICATION')),
            'effective_from' => $row['effective_from'] ?? null, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    // ------------------------------------------------------------------ payment provider profiles

    public function profiles(string $tenantId): array
    {
        return DB::table('payment_provider_profiles')->where('tenant_id', $tenantId)->orderBy('provider_type')->get()->all();
    }

    public function saveProfile(string $tenantId, array $d, string $actorId, ?string $id = null): object
    {
        foreach (['callback_profile', 'reconciliation_reference_rules', 'fees'] as $j) {
            $this->assertNoSecrets($d[$j] ?? [], $j);
        }
        if (! empty($d['api_base_url']) && ! str_starts_with((string) $d['api_base_url'], 'https://')) {
            throw ValidationException::withMessages(['api_base_url' => 'Provider API base URL must use https.']);
        }
        if (! empty($d['financial_institution_id'])) {
            $fi = DB::table('financial_institutions')->find($d['financial_institution_id']) ?? throw ValidationException::withMessages(['financial_institution_id' => 'Unknown institution.']);
            if (($d['environment'] ?? 'SANDBOX') === 'PRODUCTION' && ! in_array($fi->verification_status, FinanceReferenceCatalogue::PRODUCTION_STATUSES, true)) {
                throw ValidationException::withMessages(['financial_institution_id' => "Institution {$fi->legal_name} is {$fi->verification_status}; not usable in production."]);
            }
        }
        if (! empty($d['connection_id']) && ! DB::table('payment_provider_connections')->where('id', $d['connection_id'])->where(fn ($q) => $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))->exists()) {
            throw ValidationException::withMessages(['connection_id' => 'Unknown provider connection.']);
        }
        $vals = collect($d)->only(['provider_type', 'adapter_provider', 'financial_institution_id', 'connection_id', 'legal_entity_id', 'api_base_url', 'environment',
            'merchant_identifier', 'collection_account', 'settlement_account', 'settlement_cycle', 'effective_from', 'effective_until'])->all();
        foreach (['callback_profile' => '{}', 'reconciliation_reference_rules' => '{}', 'fees' => '[]'] as $j => $empty) {
            if (array_key_exists($j, $d)) {
                $vals[$j] = json_encode($d[$j] ?? json_decode($empty));
            }
        }
        if ($id) {
            $row = DB::table('payment_provider_profiles')->where('tenant_id', $tenantId)->where('id', $id)->first() ?? abort(404);
            if ($row->status === 'ACTIVE') {
                throw ValidationException::withMessages(['status' => 'An active profile is immutable; suspend it and create a new version.']);
            }
            DB::table('payment_provider_profiles')->where('id', $id)->update($vals + ['status' => 'CONFIG_REQUIRED', 'approved_by' => null, 'approved_at' => null, 'updated_at' => now()]);
        } else {
            $id = (string) Str::uuid();
            DB::table('payment_provider_profiles')->insert($vals + ['id' => $id, 'tenant_id' => $tenantId, 'status' => 'CONFIG_REQUIRED', 'created_by' => $actorId, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->audit->record('finance.payment_profile.saved', 'payment_provider_profile', $id, ['provider_type' => $vals['provider_type'] ?? null]);

        return DB::table('payment_provider_profiles')->find($id);
    }

    /** @return list<string> what is still missing before the profile can be submitted */
    public function profileGaps(object $p): array
    {
        $missing = [];
        if (in_array($p->provider_type, FinanceReferenceCatalogue::ELECTRONIC, true)) {
            foreach (['merchant_identifier', 'settlement_account', 'settlement_cycle', 'effective_from'] as $f) {
                if (empty($p->{$f})) {
                    $missing[] = $f;
                }
            }
            if (in_array($p->provider_type, ['MTN_MOMO', 'ORANGE_MONEY', 'CARD_GATEWAY'], true) && empty($p->connection_id)) {
                $missing[] = 'connection_id';
            }
        } else {
            foreach (['collection_account', 'effective_from'] as $f) {
                if (empty($p->{$f})) {
                    $missing[] = $f;
                }
            }
        }

        return $missing;
    }

    public function submitProfile(string $tenantId, string $id): object
    {
        $p = DB::table('payment_provider_profiles')->where('tenant_id', $tenantId)->where('id', $id)->first() ?? abort(404);
        if ($gaps = $this->profileGaps($p)) {
            throw ValidationException::withMessages(['profile' => 'Configuration incomplete: '.implode(', ', $gaps)]);
        }
        DB::table('payment_provider_profiles')->where('id', $id)->update(['status' => 'PENDING_APPROVAL', 'updated_at' => now()]);

        return DB::table('payment_provider_profiles')->find($id);
    }

    public function decideProfile(string $tenantId, string $id, string $decision, string $actorId, string $reason): object
    {
        $p = DB::table('payment_provider_profiles')->where('tenant_id', $tenantId)->where('id', $id)->first() ?? abort(404);
        if ($decision === 'APPROVE') {
            if ($p->status !== 'PENDING_APPROVAL') {
                throw ValidationException::withMessages(['status' => 'Only a submitted profile can be approved.']);
            }
            if ($p->created_by === $actorId) {
                throw ValidationException::withMessages(['approved_by' => 'Maker and checker must differ.']);
            }
            $update = ['status' => 'ACTIVE', 'approved_by' => $actorId, 'approved_at' => now()];
        } else {
            $update = ['status' => $decision === 'SUSPEND' ? 'SUSPENDED' : 'CONFIG_REQUIRED'];
        }
        DB::table('payment_provider_profiles')->where('id', $id)->update($update + ['updated_at' => now()]);
        $this->audit->record('finance.payment_profile.'.strtolower($decision), 'payment_provider_profile', $id, [], $reason);

        return DB::table('payment_provider_profiles')->find($id);
    }

    /** Gate for workflows that need a configured provider: throws while the tenant has no ACTIVE, in-date profile. */
    public function assertProviderUsable(string $tenantId, string $providerType): object
    {
        $p = DB::table('payment_provider_profiles')->where('tenant_id', $tenantId)->where('provider_type', $providerType)->where('status', 'ACTIVE')
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', now()->toDateString()))
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', now()->toDateString()))->first();
        if (! $p) {
            throw ValidationException::withMessages(['provider_type' => "Payment provider {$providerType} is CONFIG_REQUIRED for this tenant."]);
        }

        return $p;
    }

    // ------------------------------------------------------------------ GL control accounts

    /** @return list<array<string, mixed>> one row per control account: the tenant's approved mapping, else the platform baseline */
    public function controlAccounts(?string $tenantId): array
    {
        $out = [];
        foreach (array_keys(FinanceReferenceCatalogue::CONTROL_ACCOUNTS) as $control) {
            $tenant = $tenantId ? DB::table('gl_control_account_mappings')->where('tenant_id', $tenantId)->where('control_code', $control)->where('status', 'APPROVED')->orderByDesc('version')->first() : null;
            $base = DB::table('gl_control_account_mappings')->whereNull('tenant_id')->where('control_code', $control)->where('status', 'APPROVED')->orderByDesc('version')->first();
            $pending = $tenantId ? DB::table('gl_control_account_mappings')->where('tenant_id', $tenantId)->where('control_code', $control)->where('status', 'PENDING_APPROVAL')->first() : null;
            $m = $tenant ?? $base;
            $out[] = ['control_code' => $control, 'ledger_account_code' => $m?->ledger_account_code, 'scope' => $tenant ? 'TENANT' : 'PLATFORM_BASELINE',
                'data_status' => $tenant ? 'VERIFIED' : 'CONFIG_REQUIRED', 'baseline_status' => $base?->data_status, 'version' => $m?->version,
                'mapping_id' => $m?->id, 'pending_mapping_id' => $pending?->id];
        }

        return $out;
    }

    public function proposeControlAccount(string $tenantId, string $control, string $code, string $actorId, string $reason): object
    {
        if (! array_key_exists($control, FinanceReferenceCatalogue::CONTROL_ACCOUNTS)) {
            throw ValidationException::withMessages(['control_code' => "Unknown control account {$control}."]);
        }
        if (! $this->accountExists($tenantId, $code)) {
            throw ValidationException::withMessages(['ledger_account_code' => "Ledger account {$code} is not in the tenant chart."]);
        }

        return DB::transaction(function () use ($tenantId, $control, $code, $actorId, $reason) {
            DB::table('gl_control_account_mappings')->where('tenant_id', $tenantId)->where('control_code', $control)->where('status', 'PENDING_APPROVAL')
                ->update(['status' => 'REJECTED', 'updated_at' => now()]);
            $version = (int) DB::table('gl_control_account_mappings')->where('tenant_id', $tenantId)->where('control_code', $control)->lockForUpdate()->pluck('version')->max();
            $id = (string) Str::uuid();
            DB::table('gl_control_account_mappings')->insert(['id' => $id, 'tenant_id' => $tenantId, 'control_code' => $control, 'ledger_account_code' => $code,
                'version' => $version + 1, 'status' => 'PENDING_APPROVAL', 'data_status' => 'CONFIG_REQUIRED', 'source' => 'TENANT_CONFIGURATION',
                'reason' => mb_substr($reason, 0, 500), 'effective_from' => now()->toDateString(), 'created_by' => $actorId, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit->record('finance.gl_control.proposed', 'gl_control_account_mapping', $id, ['control' => $control, 'code' => $code], $reason);

            return DB::table('gl_control_account_mappings')->find($id);
        });
    }

    public function approveControlAccount(string $tenantId, string $id, string $actorId): object
    {
        return DB::transaction(function () use ($tenantId, $id, $actorId) {
            $m = DB::table('gl_control_account_mappings')->where('tenant_id', $tenantId)->where('id', $id)->lockForUpdate()->first() ?? abort(404);
            if ($m->status !== 'PENDING_APPROVAL') {
                throw ValidationException::withMessages(['status' => 'Only a pending mapping can be approved.']);
            }
            if ($m->created_by === $actorId) {
                throw ValidationException::withMessages(['approved_by' => 'Maker and checker must differ.']);
            }
            DB::table('gl_control_account_mappings')->where('tenant_id', $tenantId)->where('control_code', $m->control_code)->where('status', 'APPROVED')
                ->update(['status' => 'SUPERSEDED', 'effective_until' => now()->toDateString(), 'updated_at' => now()]);
            DB::table('gl_control_account_mappings')->where('id', $id)->update(['status' => 'APPROVED', 'data_status' => 'VERIFIED', 'approved_by' => $actorId, 'approved_at' => now(), 'updated_at' => now()]);
            $this->audit->record('finance.gl_control.approved', 'gl_control_account_mapping', $id, ['control' => $m->control_code, 'code' => $m->ledger_account_code]);

            return DB::table('gl_control_account_mappings')->find($id);
        });
    }

    // ------------------------------------------------------------------ event -> GL (view over accounting_event_mappings)

    public function glEventMappings(?string $tenantId, string $currency = 'XAF'): array
    {
        $out = [];
        foreach (FinanceReferenceCatalogue::GL_EVENTS as $spec => $event) {
            $m = $event ? DB::table('accounting_event_mappings')->where('event_code', $event)->where('status', 'ACTIVE')
                ->where(fn ($q) => $tenantId ? $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id') : $q->whereNull('tenant_id'))
                ->orderByRaw('tenant_id IS NULL ASC')->first() : null;
            $status = match (true) {
                $event === null || ! $this->mappings->isKnownEvent($event) => 'CONFIG_REQUIRED',
                $m === null => 'CONFIG_REQUIRED',
                $m->tenant_id !== null => 'VERIFIED',
                default => 'PLATFORM_NORMALIZED',
            };
            $out[] = ['event_code' => $spec, 'accounting_event' => $event, 'debit_account' => $m?->debit_account_code, 'credit_account' => $m?->credit_account_code,
                'currency_rule' => $currency, 'effective_from' => $m?->effective_from, 'version' => $m?->version, 'scope' => $m ? ($m->tenant_id ? 'TENANT' : 'PLATFORM_DEFAULT') : null,
                'approval_status' => $status, 'missing' => $event === null ? 'No accounting event catalogued yet' : ($m === null ? 'No mapping' : null)];
        }

        return $out;
    }

    // ------------------------------------------------------------------ cost centres

    public function costCentres(string $tenantId): array
    {
        return DB::table('cost_centres')->where('tenant_id', $tenantId)->orderBy('code')->get()->all();
    }

    public function createCostCentre(string $tenantId, array $d, string $actorId): object
    {
        $code = strtoupper(trim($d['code']));
        if (DB::table('cost_centres')->where('tenant_id', $tenantId)->where('code', $code)->exists()) {
            throw ValidationException::withMessages(['code' => "Cost centre {$code} already exists."]);
        }
        if (! empty($d['parent_id']) && ! DB::table('cost_centres')->where('tenant_id', $tenantId)->where('id', $d['parent_id'])->exists()) {
            throw ValidationException::withMessages(['parent_id' => 'Unknown parent cost centre.']);
        }
        $id = (string) Str::uuid();
        DB::table('cost_centres')->insert(['id' => $id, 'tenant_id' => $tenantId, 'code' => $code, 'name' => $d['name'], 'parent_id' => $d['parent_id'] ?? null,
            'branch_id' => $d['branch_id'] ?? null, 'status' => 'ACTIVE', 'effective_from' => $d['effective_from'] ?? now()->toDateString(),
            'effective_until' => $d['effective_until'] ?? null, 'created_by' => $actorId, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit->record('finance.cost_centre.created', 'cost_centre', $id, ['code' => $code]);

        return DB::table('cost_centres')->find($id);
    }

    public function setCostCentreStatus(string $tenantId, string $id, string $status, string $reason): object
    {
        $row = DB::table('cost_centres')->where('tenant_id', $tenantId)->where('id', $id)->first() ?? abort(404);
        DB::table('cost_centres')->where('id', $id)->update(['status' => $status, 'effective_until' => $status === 'INACTIVE' ? now()->toDateString() : null, 'updated_at' => now()]);
        $this->audit->recordChange('finance.cost_centre.status', 'cost_centre', $id, ['status' => $row->status], ['status' => $status], $reason);

        return DB::table('cost_centres')->find($id);
    }

    /** Validates a journal-line dimension set: a cost_centre_id must be an active, in-date cost centre of the tenant. */
    public function assertDimensions(?string $tenantId, array $dimensions): void
    {
        $cc = $dimensions['cost_centre_id'] ?? null;
        if ($cc === null || $cc === '') {
            return;
        }
        $ok = $tenantId && Str::isUuid((string) $cc) && DB::table('cost_centres')->where('tenant_id', $tenantId)->where('id', $cc)->where('status', 'ACTIVE')
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', now()->toDateString()))->exists();
        if (! $ok) {
            throw ValidationException::withMessages(['dimensions.cost_centre_id' => 'Cost centre is unknown, inactive or belongs to another tenant.']);
        }
    }

    // ------------------------------------------------------------------ helpers

    private function accountExists(string $tenantId, string $code): bool
    {
        return array_key_exists($code, DefaultChartOfAccounts::ACCOUNTS)
            || DB::table('ledger_accounts')->where('code', $code)->where(fn ($q) => $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))->exists();
    }

    private function assertNoSecrets(mixed $value, string $field): void
    {
        if (! is_array($value)) {
            return;
        }
        foreach ($value as $k => $v) {
            $key = strtolower((string) $k);
            if ($key === 'pin') {
                throw ValidationException::withMessages([$field => 'Credentials (pin) are never stored in provider configuration; use the secrets manager.']);
            }
            foreach (FinanceReferenceCatalogue::SECRET_KEYS as $s) {
                if (str_contains($key, $s)) {
                    throw ValidationException::withMessages([$field => "Credentials ({$k}) are never stored in provider configuration; use the secrets manager."]);
                }
            }
            $this->assertNoSecrets($v, $field);
        }
    }

    private function decorate(object $r): object
    {
        foreach (['phones', 'branches', 'aliases'] as $j) {
            $r->{$j} = is_string($r->{$j}) ? json_decode($r->{$j}, true) : $r->{$j};
        }
        $r->data_status = FinanceReferenceCatalogue::toDataStatus($r->verification_status);
        $r->production_usable = in_array($r->verification_status, FinanceReferenceCatalogue::PRODUCTION_STATUSES, true);

        return $r;
    }
}
