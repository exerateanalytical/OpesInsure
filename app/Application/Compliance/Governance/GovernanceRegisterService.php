<?php

declare(strict_types=1);

namespace App\Application\Compliance\Governance;

use App\Application\Audit\AuditWriter;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * REQ-CMP-003 ICT governance (Reg. 010-24) and vendor / outsourcing governance registers.
 *
 * STRUCTURE ONLY — OQ-8.1: the owner has not supplied the requirement text, so this service encodes no
 * obligation, reporting deadline, review frequency or rating threshold. It keeps tenant-scoped, audited,
 * versioned register rows; owner-defined fields go in `attributes`. The only rules are structural
 * (referential integrity inside the tenant, exit-plan maker-checker).
 */
final class GovernanceRegisterService
{
    public const RATINGS = ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'];

    /** register slug => table */
    public const REGISTERS = [
        'ict-assets' => 'governance_ict_assets',
        'ict-incidents' => 'governance_ict_incidents',
        'vendors' => 'governance_vendors',
        'outsourcing-contracts' => 'governance_outsourcing_contracts',
        'due-diligence-reviews' => 'governance_due_diligence_reviews',
        'exit-plans' => 'governance_exit_plans',
    ];

    public function __construct(private readonly AuditWriter $audit) {}

    /** @return array<string, mixed> */
    private function rules(string $register, string $tenant, bool $update): array
    {
        $req = $update ? 'sometimes' : 'required';
        $in = fn (string $table) => Rule::exists($table, 'id')->where('tenant_id', $tenant);
        $member = Rule::exists('tenant_memberships', 'user_id')->where('tenant_id', $tenant)->where('status', 'ACTIVE');
        $rating = ['nullable', Rule::in(self::RATINGS)];
        $common = ['attributes' => 'sometimes|array'];

        return $common + match ($register) {
            'ict-assets' => ['asset_code' => "$req|string|max:64", 'name' => "$req|string|max:255", 'category' => "$req|string|max:64", 'criticality' => $rating,
                'owner_user_id' => ['nullable', 'uuid', $member], 'vendor_id' => ['nullable', 'uuid', $in('governance_vendors')], 'status' => 'sometimes|in:ACTIVE,RETIRED'],
            'ict-incidents' => ['ict_asset_id' => ['nullable', 'uuid', $in('governance_ict_assets')], 'title' => "$req|string|max:255", 'description' => 'nullable|string|max:10000',
                'severity' => [$req, Rule::in(self::RATINGS)], 'status' => 'sometimes|in:OPEN,CONTAINED,RESOLVED,CLOSED', 'detected_at' => "$req|date",
                'resolved_at' => 'nullable|date', 'reported_externally_at' => 'nullable|date', 'external_reference' => 'nullable|string|max:255',
                'compliance_case_id' => ['nullable', 'uuid', $in('compliance_cases')]],
            'vendors' => ['vendor_code' => "$req|string|max:64", 'name' => "$req|string|max:255", 'party_id' => 'nullable|uuid|exists:parties,id', 'services' => 'nullable|string|max:4000',
                'is_outsourcing' => 'sometimes|boolean', 'criticality' => $rating, 'risk_rating' => $rating, 'status' => 'sometimes|in:ACTIVE,UNDER_REVIEW,EXITING,EXITED'],
            'outsourcing-contracts' => ['vendor_id' => [$req, 'uuid', $in('governance_vendors')], 'contract_reference' => "$req|string|max:128", 'service_description' => "$req|string|max:4000",
                'start_on' => "$req|date", 'end_on' => 'nullable|date', 'risk_rating' => $rating, 'status' => 'sometimes|in:DRAFT,ACTIVE,TERMINATING,TERMINATED',
                'document_id' => ['nullable', 'uuid', $in('documents')]],
            'due-diligence-reviews' => ['vendor_id' => [$req, 'uuid', $in('governance_vendors')], 'contract_id' => ['nullable', 'uuid', $in('governance_outsourcing_contracts')],
                'review_date' => "$req|date", 'outcome' => "$req|in:SATISFACTORY,CONDITIONAL,UNSATISFACTORY", 'risk_rating' => $rating, 'next_review_on' => 'nullable|date', 'notes' => 'nullable|string|max:10000'],
            'exit-plans' => ['contract_id' => [$req, 'uuid', $in('governance_outsourcing_contracts')], 'summary' => "$req|string|max:20000", 'last_tested_on' => 'nullable|date'],
            default => throw ValidationException::withMessages(['register' => 'Unknown governance register.']),
        };
    }

    public function table(string $register): string
    {
        return self::REGISTERS[$register] ?? abort(404);
    }

    public function list(string $register, string $tenant, int $perPage = 50)
    {
        return DB::table($this->table($register))->where('tenant_id', $tenant)->orderByDesc('created_at')->paginate(min(max($perPage, 1), 100));
    }

    public function find(string $register, string $tenant, string $id): object
    {
        return DB::table($this->table($register))->where('tenant_id', $tenant)->where('id', $id)->first() ?? abort(404);
    }

    public function create(string $register, string $tenant, array $input, User $u): object
    {
        $table = $this->table($register);
        $d = Validator::make($input, $this->rules($register, $tenant, false))->validate();
        $id = (string) Str::uuid();
        $row = ['id' => $id, 'tenant_id' => $tenant, 'created_at' => now(), 'updated_at' => now()] + $this->encode($d);
        $row += match ($register) {
            'ict-incidents' => ['incident_number' => 'ICT-'.now()->format('Ym').'-'.strtoupper(Str::random(8)), 'recorded_by' => $u->id],
            'due-diligence-reviews' => ['reviewed_by' => $u->id],
            'exit-plans' => ['prepared_by' => $u->id, 'status' => 'DRAFT'],
            default => [],
        };
        DB::table($table)->insert($row);
        $this->audit->record('governance.'.$register.'.created', $table, $id, []);

        return $this->find($register, $tenant, $id);
    }

    public function update(string $register, string $tenant, string $id, array $input, User $u): object
    {
        $table = $this->table($register);
        $d = Validator::make($input, $this->rules($register, $tenant, true))->validate();

        return DB::transaction(function () use ($register, $tenant, $id, $d, $table) {
            $old = DB::table($table)->where('tenant_id', $tenant)->where('id', $id)->lockForUpdate()->first() ?? abort(404);
            $changes = $this->encode($d);
            if ($register === 'exit-plans' && $old->status === 'APPROVED' && $changes !== []) {
                // An approved exit plan changes only through a new approval.
                $changes += ['status' => 'DRAFT', 'approved_by' => null, 'approved_at' => null];
            }
            DB::table($table)->where('id', $id)->update($changes + ['version' => $old->version + 1, 'updated_at' => now()]);
            $this->audit->record('governance.'.$register.'.updated', $table, $id, ['fields' => array_keys($changes)]);

            return $this->find($register, $tenant, $id);
        });
    }

    public function approveExitPlan(string $tenant, string $id, User $u): object
    {
        return DB::transaction(function () use ($tenant, $id, $u) {
            $p = DB::table('governance_exit_plans')->where('tenant_id', $tenant)->where('id', $id)->lockForUpdate()->first() ?? abort(404);
            if ($p->status !== 'DRAFT' || $p->prepared_by === $u->id) {
                throw ValidationException::withMessages(['status' => __('wave9.maker_checker')]);
            }
            DB::table('governance_exit_plans')->where('id', $id)->update(['status' => 'APPROVED', 'approved_by' => $u->id, 'approved_at' => now(), 'version' => $p->version + 1, 'updated_at' => now()]);
            $this->audit->record('governance.exit-plans.approved', 'governance_exit_plans', $id, []);

            return $this->find('exit-plans', $tenant, $id);
        });
    }

    private function encode(array $d): array
    {
        if (array_key_exists('attributes', $d)) {
            $d['attributes'] = json_encode($d['attributes'], JSON_THROW_ON_ERROR);
        }

        return $d;
    }
}
