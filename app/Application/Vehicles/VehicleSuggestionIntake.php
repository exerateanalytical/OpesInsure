<?php

declare(strict_types=1);

namespace App\Application\Vehicles;

use App\Application\Identity\PartyResolver;
use App\Models\RiskAsset;
use App\Models\User;
use App\Models\Vehicles\VehicleMake;
use App\Models\Vehicles\VehicleMasterReview;
use App\Models\Vehicles\VehicleReferenceValue;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * REQ-DUP-013: the single intake for vehicle "Other / Not listed" entries.
 *
 * Canonical: POST /api/v1/master-data/suggestions with domain "vehicle"
 *   {domain:"vehicle", list:"models", parent:<make code or typed make>, text:<typed model>, attributes:{model_year?, body_type?, powertrain?, usage?, vin?, registration_number?, engine_number?, risk_asset_id?}}
 *   {domain:"vehicle", list:"makes", text:<typed make>, attributes:{…}}   (make-only entry)
 * Deprecated alias: POST /api/v1/mobile/vehicles/master-review {make, model, …} (same queue).
 *
 * Entries land in the vehicle master's own queue (vehicle_master_review_queue,
 * Filament "Vehicle master data > Review queue"), never in the generic
 * master-data tables, because domain "vehicle" is owned by the vehicle master.
 * Response mirrors the generic suggestion shape: {status, value, review}.
 */
final class VehicleSuggestionIntake
{
    public const DOMAIN = 'vehicle';

    public function __construct(
        private readonly VehicleMasterReviewService $reviews,
        private readonly VehicleCatalogueService $catalogue,
        private readonly PartyResolver $parties,
    ) {}

    /** Validation rules for the optional vehicle attributes (shared by both routes). */
    public static function attributeRules(string $prefix = ''): array
    {
        $range = VehicleCatalogueService::modelYearRange();
        $codes = fn (string $group) => Rule::in(VehicleReferenceValue::where('group', $group)->pluck('code')->all());

        return [
            $prefix.'model_year' => "nullable|integer|between:{$range['min']},{$range['max']}",
            $prefix.'body_type' => ['nullable', 'string', $codes('body_type')],
            $prefix.'powertrain' => ['nullable', 'string', $codes('powertrain')],
            $prefix.'usage' => ['nullable', 'string', $codes('usage')],
            $prefix.'vin' => 'nullable|string|max:40',
            $prefix.'registration_number' => 'nullable|string|max:40',
            $prefix.'engine_number' => 'nullable|string|max:60',
            $prefix.'risk_asset_id' => 'nullable|uuid',
            // Manual-entry fields of the Cameroon & Africa config (allowed_manual_fields).
            $prefix.'generation' => 'nullable|string|max:120',
            $prefix.'engine_label' => 'nullable|string|max:160',
            $prefix.'power_hp' => 'nullable|integer|between:1,3000',
            $prefix.'displacement_cc' => 'nullable|integer|between:1,30000',
            $prefix.'fuel_type' => ['nullable', 'string', $codes('powertrain')],
            $prefix.'transmission' => ['nullable', 'string', $codes('transmission')],
            $prefix.'drivetrain' => ['nullable', 'string', $codes('drive_type')],
        ];
    }

    /**
     * Canonical-shape entry point.
     *
     * @param  array<string, mixed>  $input  already validated {list, text, parent?, attributes?}
     * @return array{status: string, value: ?array, review: ?array}
     */
    public function suggest(array $input, ?User $user): array
    {
        $list = strtolower((string) $input['list']);
        $text = trim((string) $input['text']);
        $attributes = array_intersect_key((array) ($input['attributes'] ?? []), self::attributeRules());

        if (in_array($list, ['makes', 'make'], true)) {
            if ($make = $this->catalogue->resolveMake($text)) {
                return ['status' => 'MATCHED', 'value' => ['make' => $make->code, 'model' => null], 'review' => null];
            }
            $makeText = $text;
            $modelText = '';
        } elseif (in_array($list, ['models', 'model'], true)) {
            $parent = trim((string) ($input['parent'] ?? ''));
            if ($parent === '') {
                throw ValidationException::withMessages(['parent' => 'Give the make (code or name) as parent for a model suggestion.']);
            }
            $make = VehicleMake::where('code', strtoupper($parent))->where('active', true)->first() ?? $this->catalogue->resolveMake($parent);
            if ($make && ($model = $this->catalogue->resolveModel($make, $text))) {
                return ['status' => 'MATCHED', 'value' => ['make' => $make->code, 'model' => $model->code], 'review' => null];
            }
            $makeText = $make?->name ?? $parent;
            $modelText = $text;
        } else {
            throw ValidationException::withMessages(['list' => 'Vehicle suggestions are for lists "makes" or "models"; other vehicle lists are admin-managed.']);
        }

        $review = $this->submit(['make' => $makeText, 'model' => $modelText] + $attributes, $user);

        return ['status' => $review->status, 'value' => null, 'review' => $this->present($review)];
    }

    /** Queue one entry (both routes). Checks risk_asset_id ownership. */
    public function submit(array $data, ?User $user): VehicleMasterReview
    {
        $tenantId = null;
        if (! empty($data['risk_asset_id'])) {
            $party = $user ? $this->parties->forUser($user) : null;
            $asset = $party ? RiskAsset::where('id', $data['risk_asset_id'])->where('party_id', $party->id)->first() : null;
            if (! $asset) {
                throw ValidationException::withMessages(['risk_asset_id' => 'Unknown vehicle.']);
            }
            $tenantId = $asset->tenant_id;
        }

        $data = array_filter($data, fn ($v) => $v !== null);
        if (! isset($data['powertrain']) && isset($data['fuel_type'])) {
            $data['powertrain'] = $data['fuel_type'];
        }

        // Duplicate check against the open queue: the same make/model already awaiting review
        // is reused (unless this entry must be linked to the caller's own vehicle record).
        $open = $this->openDuplicate((string) $data['make'], (string) ($data['model'] ?? ''));
        if ($open && empty($data['risk_asset_id'])) {
            $payload = $open->payload ?? [];
            $payload['duplicate_submissions'] = min(1000, (int) ($payload['duplicate_submissions'] ?? 0) + 1);
            $open->update(['payload' => $payload]);

            return $open;
        }
        if ($open) {
            $data['duplicate_of'] = $open->id;
        }

        return $this->reviews->submit($data, $user, $tenantId);
    }

    private function openDuplicate(string $make, string $model): ?VehicleMasterReview
    {
        $mk = VehicleText::normalize($make);
        $md = VehicleText::normalize($model);

        return VehicleMasterReview::where('status', VehicleMasterReview::STATUS_PENDING)->latest()->limit(500)->get()
            ->first(fn ($r) => VehicleText::normalize($r->make_text) === $mk && VehicleText::normalize($r->model_text) === $md);
    }

    /** @return array<string, mixed> */
    public function present(VehicleMasterReview $review): array
    {
        return [
            'id' => $review->id,
            'domain' => self::DOMAIN,
            'list' => $review->model_text === '' ? 'makes' : 'models',
            'raw_input' => trim($review->make_text.' '.$review->model_text),
            'status' => $review->status,
            'make' => $review->make_text,
            'model' => $review->model_text === '' ? null : $review->model_text,
            'model_year' => $review->model_year,
            'duplicate_of' => $review->payload['duplicate_of'] ?? null,
        ];
    }
}
