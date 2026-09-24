<?php

declare(strict_types=1);

namespace App\Application\Vehicles;

use App\Models\User;
use App\Models\Vehicles\RiskAssetVehicle;
use App\Models\Vehicles\VehicleMake;
use App\Models\Vehicles\VehicleMasterChange;
use App\Models\Vehicles\VehicleMasterReview;
use App\Models\Vehicles\VehicleModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Can't find your vehicle? Add manually" entries. Each lands as
 * MASTER_DATA_REVIEW_REQUIRED; an admin either approves it as a new
 * make/model (provenance CUSTOMER_SUBMITTED, status UNVERIFIED) or merges it
 * into an existing make/model (the typed text becomes an alias). Linked
 * vehicle records are reconciled either way; who/when is recorded.
 */
final class VehicleMasterReviewService
{
    public function __construct(private VehicleMasterAdminService $admin, private VehicleCatalogueService $catalogue) {}

    /** @param array<string, mixed> $data */
    public function submit(array $data, ?User $user, ?string $tenantId = null): VehicleMasterReview
    {
        $review = VehicleMasterReview::create([
            'status' => VehicleMasterReview::STATUS_PENDING,
            'make_text' => trim((string) $data['make']),
            'model_text' => trim((string) $data['model']),
            'model_year' => $data['model_year'] ?? null,
            'body_type' => $data['body_type'] ?? null,
            'powertrain' => $data['powertrain'] ?? null,
            'usage' => $data['usage'] ?? null,
            'vin' => isset($data['vin']) ? strtoupper(trim((string) $data['vin'])) : null,
            'registration_number' => isset($data['registration_number']) ? strtoupper(trim((string) $data['registration_number'])) : null,
            'engine_number' => $data['engine_number'] ?? null,
            'payload' => array_diff_key($data, array_flip(['make', 'model'])),
            'tenant_id' => $tenantId,
            'risk_asset_id' => $data['risk_asset_id'] ?? null,
            'submitted_by' => $user?->id,
        ]);

        // Pre-link the typed make if it already exists — helps the reviewer.
        if ($make = $this->catalogue->resolveMake($review->make_text)) {
            $review->update(['resolved_make_id' => $make->id]);
        }

        if ($review->risk_asset_id) {
            RiskAssetVehicle::where('risk_asset_id', $review->risk_asset_id)->update(['review_id' => $review->id, 'reconciliation_status' => 'PENDING_REVIEW']);
        }

        return $review;
    }

    /** @param array<string, mixed> $makeDefaults segment / country_of_origin for a brand-new make */
    public function approveAsNew(VehicleMasterReview $review, User $admin, array $makeDefaults = [], ?string $notes = null): VehicleMasterReview
    {
        $this->assertPending($review);

        return DB::transaction(function () use ($review, $admin, $makeDefaults, $notes) {
            $make = $this->catalogue->resolveMake($review->make_text)
                ?? $this->admin->createMake(['name' => $review->make_text, 'cameroon_status' => 'UNVERIFIED'] + array_intersect_key($makeDefaults, array_flip(['segment', 'country_of_origin', 'code'])), $admin, 'CUSTOMER_SUBMITTED');
            $model = $this->catalogue->resolveModel($make, $review->model_text)
                ?? $this->admin->createModel($make, ['name' => $review->model_text], $admin, 'CUSTOMER_SUBMITTED');

            return $this->resolve($review, 'APPROVED_NEW', $make, $model, $admin, $notes);
        });
    }

    public function merge(VehicleMasterReview $review, User $admin, string $makeId, ?string $modelId = null, ?string $notes = null): VehicleMasterReview
    {
        $this->assertPending($review);

        return DB::transaction(function () use ($review, $admin, $makeId, $modelId, $notes) {
            $make = VehicleMake::findOrFail($makeId);
            $model = $modelId ? VehicleModel::where('make_id', $make->id)->findOrFail($modelId) : null;

            $this->admin->addMakeAlias($make, $review->make_text, $admin);
            if ($model) {
                $this->admin->addModelAlias($model, $review->model_text, $admin);
            }

            return $this->resolve($review, 'MERGED', $make, $model, $admin, $notes);
        });
    }

    public function reject(VehicleMasterReview $review, User $admin, string $notes): VehicleMasterReview
    {
        $this->assertPending($review);
        $review->update(['status' => 'REJECTED', 'reviewed_by' => $admin->id, 'reviewed_at' => now(), 'review_notes' => $notes]);
        $this->log($review, 'REJECTED', $admin, ['notes' => $notes]);

        return $review;
    }

    private function resolve(VehicleMasterReview $review, string $status, VehicleMake $make, ?VehicleModel $model, User $admin, ?string $notes): VehicleMasterReview
    {
        $review->update([
            'status' => $status,
            'resolved_make_id' => $make->id,
            'resolved_model_id' => $model?->id,
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
            'review_notes' => $notes,
        ]);

        RiskAssetVehicle::where('review_id', $review->id)->update([
            'make_id' => $make->id,
            'model_id' => $model?->id,
            'reconciliation_status' => $model ? 'MATCHED' : 'PARTIAL',
        ]);

        $this->log($review, $status, $admin, ['make' => $make->code, 'model' => $model?->code]);

        return $review->fresh();
    }

    private function assertPending(VehicleMasterReview $review): void
    {
        if ($review->status !== VehicleMasterReview::STATUS_PENDING) {
            throw ValidationException::withMessages(['status' => 'This entry has already been reviewed.']);
        }
    }

    private function log(VehicleMasterReview $review, string $action, User $admin, array $after): void
    {
        VehicleMasterChange::create([
            'entity_type' => 'vehicle_master_review', 'entity_id' => $review->id, 'action' => $action,
            'before' => ['make_text' => $review->make_text, 'model_text' => $review->model_text],
            'after' => $after, 'actor_id' => $admin->id, 'occurred_at' => now(),
        ]);
    }
}
