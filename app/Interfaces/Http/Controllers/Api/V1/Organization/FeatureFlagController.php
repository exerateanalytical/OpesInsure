<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\Organization;

use App\Application\Settings\FeatureFlags;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** REQ-SEC-004 — scoped feature flags. */
final class FeatureFlagController
{
    public function __construct(private readonly TenantContext $tenant, private readonly FeatureFlags $flags)
    {
    }

    /** Evaluate for the caller tenant (+ optional branch/product). */
    public function evaluate(Request $r): JsonResponse
    {
        $d = $r->validate(['key' => 'required|string|max:96', 'branch_id' => 'nullable|uuid', 'product_code' => 'nullable|string|max:64']);
        $tenant = DB::table('tenants')->where('id', $this->tenant->id())->first(['id', 'country_code']);
        if (! empty($d['branch_id'])) {
            abort_unless(DB::table('tenant_branches')->where(['id' => $d['branch_id'], 'tenant_id' => $tenant->id])->exists(), 404);
        }
        $scope = ['tenant_id' => $tenant->id, 'country_code' => $tenant->country_code, 'branch_id' => $d['branch_id'] ?? null, 'product_code' => $d['product_code'] ?? null];

        return response()->json(['data' => ['key' => $d['key'], 'enabled' => $this->flags->enabled($d['key'], $scope)]]);
    }

    public function index(): JsonResponse
    {
        $q = DB::table('feature_flags')->orderBy('key');
        if (! $this->isPlatform()) {
            $q->where('tenant_id', $this->tenant->id());
        }

        return response()->json(['data' => $q->limit(500)->get()]);
    }

    /** Platform tenant may set any scope; other tenants only their own tenant/branch scope. */
    public function upsert(Request $r): JsonResponse
    {
        $d = $r->validate([
            'key' => 'required|string|max:96',
            'enabled' => 'required|boolean',
            'environment' => 'nullable|string|max:24',
            'country_code' => 'nullable|string|size:2',
            'tenant_id' => 'nullable|uuid|exists:tenants,id',
            'branch_id' => 'nullable|uuid',
            'product_code' => 'nullable|string|max:64',
            'description' => 'nullable|string|max:500',
        ]);
        if (! $this->isPlatform()) {
            abort_if(isset($d['tenant_id']) && $d['tenant_id'] !== $this->tenant->id(), 403, 'Only the platform may set flags for other tenants.');
            $d['tenant_id'] = $this->tenant->id();
        }

        return response()->json(['data' => $this->flags->set($d, $r->user()->id)]);
    }

    private function isPlatform(): bool
    {
        return DB::table('tenants')->where('id', $this->tenant->id())->value('type') === 'PLATFORM';
    }
}
