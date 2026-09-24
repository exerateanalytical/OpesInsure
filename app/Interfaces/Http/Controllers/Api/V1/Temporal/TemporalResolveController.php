<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\Temporal;

use App\Application\Temporal\ReferenceDateResolver;
use App\Application\Temporal\ReferenceInstant;
use App\Application\Temporal\TemporalResolutionException;
use App\Application\Temporal\VersionResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Diagnostic, read-only (ICE E1 §1.10). Resolves one artifact either at an
 * explicit `at` instant or via the operation's reference-date rule applied to
 * `anchor_value`, optionally as known at `as_of` (bitemporal replay).
 */
final class TemporalResolveController
{
    public function resolve(Request $r, VersionResolver $versions, ReferenceDateResolver $rules): JsonResponse
    {
        $d = $r->validate([
            'artifact_type' => 'required|string|max:48', 'key' => 'required|array', 'key.*' => 'nullable|string|max:128',
            'at' => 'required_without:operation|nullable|date', 'operation' => 'required_without:at|nullable|string|max:48',
            'anchor_value' => 'nullable|date', 'as_of' => 'nullable|date', 'timezone' => 'nullable|timezone',
        ]);
        $tz = $d['timezone'] ?? (string) config('app.timezone');
        try {
            $def = $versions->definition($d['artifact_type']);
            $at = isset($d['at'])
                ? ReferenceInstant::at($d['at'], $tz)
                : $rules->for($d['operation'], $this->anchorSubject($d['operation'], $d['artifact_type'], $def->rule_category, $d['anchor_value'] ?? null, $rules), $d['artifact_type'], $def->rule_category, $tz);
            $resolved = $versions->resolve($d['artifact_type'], $d['key'], $at, isset($d['as_of']) ? CarbonImmutable::parse($d['as_of']) : null);
        } catch (TemporalResolutionException $e) {
            return response()->json(['error' => ['code' => $e->reasonCode, 'artifact_type' => $e->artifactType, 'key' => $e->key, 'at' => $e->at]], 422);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => ['code' => 'VALIDATION_FAILED', 'message' => $e->getMessage()]], 422);
        }

        return response()->json(['data' => ['reference' => $at->toArray(), 'recorded_as_of' => $d['as_of'] ?? null, 'version' => $resolved->toArray()]]);
    }

    public function resolvedVersions(string $subjectType, string $subjectId): JsonResponse
    {
        $rows = DB::table('transaction_resolved_versions')->where('subject_type', $subjectType)->where('subject_id', $subjectId)
            ->where('tenant_id', app(\App\Domain\Tenancy\TenantContext::class)->id())->orderBy('created_at')->get()
            ->map(fn ($row) => [...(array) $row, 'versions' => json_decode($row->versions, true)]);

        return response()->json(['data' => $rows]);
    }

    /** @return array<string, mixed> */
    private function anchorSubject(string $operation, string $artifactType, string $category, ?string $value, ReferenceDateResolver $rules): array
    {
        $anchor = $rules->rule($operation, $artifactType, $category)->anchor;
        $attribute = ['REQUESTED_AT' => 'requested_at', 'OFFER_LOCK' => 'locked_at', 'INCEPTION' => 'coverage_starts_at', 'EFFECTIVE_AT' => 'effective_at',
            'LOSS_OCCURRED_AT' => 'loss_occurred_at', 'DECISION_AT' => 'decided_at', 'VALUE_DATE' => 'value_date', 'PERIOD_END' => 'period_end'][$anchor] ?? null;

        return $attribute && $value ? [$attribute => $value] : [];
    }
}
