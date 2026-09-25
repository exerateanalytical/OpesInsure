<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\PlatformConfiguration;

use App\Application\Configuration\ConfigurationInheritance;
use App\Application\Configuration\PlatformSetupService;
use App\Application\Demo\DemoCoverageReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** REQ-SET-001 platform setup wizard, REQ-SET-004 configuration inheritance, REQ-SEED-005 demo coverage. */
final class PlatformConfigurationController
{
    private const SCOPE_FIELDS = ['insurer_id' => 'INSURER', 'agreement_id' => 'BROKER_AGREEMENT', 'broker_id' => 'BROKER', 'branch_id' => 'BRANCH', 'user_id' => 'USER'];

    public function setup(PlatformSetupService $setup): JsonResponse
    {
        return response()->json(['data' => $setup->status()]);
    }

    public function draftIdentity(Request $r, PlatformSetupService $setup): JsonResponse
    {
        $d = $r->validate(['changes' => 'required|array', 'reason' => 'required|string|min:5|max:2000', 'effective_from' => 'nullable|date']);

        return response()->json(['data' => $setup->draftIdentity($r->user(), $d['changes'], $d['reason'], $d['effective_from'] ?? null)], 201);
    }

    public function completeSetup(Request $r, PlatformSetupService $setup): JsonResponse
    {
        return response()->json(['data' => $setup->complete($r->user())]);
    }

    public function inheritanceKeys(ConfigurationInheritance $inheritance): JsonResponse
    {
        return response()->json(['data' => ['levels' => $inheritance->levels(), 'keys' => $inheritance->keys()]]);
    }

    public function resolve(Request $r, ConfigurationInheritance $inheritance): JsonResponse
    {
        $d = $r->validate(['key' => 'required|string|max:120', 'on' => 'nullable|date', ...array_fill_keys(array_keys(self::SCOPE_FIELDS), 'nullable|uuid')]);

        return response()->json(['data' => $inheritance->resolve($d['key'], $this->scope($d), $d['on'] ?? null)]);
    }

    public function draftOverride(Request $r, ConfigurationInheritance $inheritance): JsonResponse
    {
        $d = $r->validate(['key' => 'required|string|max:120', 'scope_level' => 'required|string|in:'.implode(',', $inheritance->levels()), 'scope_id' => 'nullable|uuid',
            'value' => 'present', 'reason' => 'required|string|min:5|max:2000', 'effective_from' => 'nullable|date', 'dry_run' => 'sometimes|boolean',
            ...array_fill_keys(array_keys(self::SCOPE_FIELDS), 'nullable|uuid')]);
        $ancestors = $this->scope($d);
        if ($r->boolean('dry_run')) {
            $inheritance->assertAllowed($d['key'], $d['scope_level'], $d['value'], $ancestors, $d['effective_from'] ?? null);

            return response()->json(['data' => ['allowed' => true]]);
        }

        return response()->json(['data' => $inheritance->draftOverride($r->user(), $d['key'], $d['scope_level'], $d['scope_id'] ?? null, $d['value'], $d['reason'], $ancestors, $d['effective_from'] ?? null)], 201);
    }

    public function demoCoverage(DemoCoverageReport $report): JsonResponse
    {
        return response()->json(['data' => $report->build()]);
    }

    private function scope(array $d): array
    {
        $scope = [];
        foreach (self::SCOPE_FIELDS as $field => $level) {
            if (! empty($d[$field])) {
                $scope[$level] = $d[$field];
            }
        }

        return $scope;
    }
}
