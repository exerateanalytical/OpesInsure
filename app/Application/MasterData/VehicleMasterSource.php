<?php

declare(strict_types=1);

namespace App\Application\MasterData;

use App\Application\Vehicles\VehicleCatalogueService;
use App\Models\Vehicles\VehicleMake;
use App\Models\Vehicles\VehicleModel;
use App\Models\Vehicles\VehicleReferenceValue;

/**
 * Domain "vehicle" is owned by the vehicle master, never copied into the
 * generic tables. Lists:
 *   makes / models (aliases make / model)  → vehicle_makes / vehicle_models
 *   any vehicle_reference_values group      → e.g. vehicle_class, usage, body_type
 * so flows (fleet vehicles, fleet classes/usages) reference one canonical set.
 *
 * LEGACY_CODES keeps the codes the specialty fleet lists used before they were
 * pointed at the vehicle master (they resolve to the canonical code; nothing
 * was deleted). MOTORCYCLE / TRICYCLE have no canonical vehicle class yet and
 * go through "Other / Not listed" review.
 */
final class VehicleMasterSource
{
    public const DOMAIN = 'vehicle';

    public const LEGACY_CODES = [
        'vehicle_class' => ['PRIVATE_CAR' => 'PRIVATE_PASSENGER', 'LIGHT_COMMERCIAL' => 'LIGHT_COMMERCIAL', 'HEAVY_GOODS' => 'GOODS_VEHICLE_HEAVY', 'BUS_COACH' => 'BUS', 'SPECIAL_PLANT' => 'SPECIAL_VEHICLE'],
        'usage' => ['PRIVATE_USE' => 'PRIVATE_PERSONAL', 'BUSINESS_USE' => 'COMPANY', 'CARRIAGE_OF_OWN_GOODS' => 'GOODS_TRANSPORT', 'CARRIAGE_FOR_HIRE' => 'GOODS_TRANSPORT', 'PUBLIC_PASSENGER_TRANSPORT' => 'PUBLIC_TRANSPORT', 'RENTAL' => 'CAR_RENTAL'],
    ];

    public static function listCode(string $list): ?string
    {
        return match ($list) {
            'makes', 'make' => 'makes',
            'models', 'model' => 'models',
            default => self::available() && VehicleReferenceValue::where('group', $list)->exists() ? $list : null,
        };
    }

    public static function available(): bool
    {
        return class_exists(VehicleMake::class) && class_exists(VehicleCatalogueService::class);
    }

    /** Canonical code for a (possibly legacy) code. */
    public static function canonical(string $list, string $code): string
    {
        return self::LEGACY_CODES[$list][$code] ?? $code;
    }

    /** @return array<int, array<string, mixed>>|null */
    public function search(string $list, ?string $q, ?string $parent, int $limit = 50): ?array
    {
        if (! self::available() || ! ($code = self::listCode($list))) {
            return null;
        }
        $svc = app(VehicleCatalogueService::class);
        if ($code === 'makes') {
            return $svc->searchMakes($q, null, false, $limit)['items']
                ->map(fn ($m) => ['code' => $m->code, 'label' => ['en' => $m->name, 'fr' => $m->name], 'aliases' => $m->aliases->pluck('alias')->all()])->values()->all();
        }
        if ($code === 'models') {
            $make = $parent ? VehicleMake::where('code', strtoupper($parent))->where('active', true)->first() : null;

            return $make ? $svc->modelsFor($make, $q)->take($limit)->map(fn ($m) => ['code' => $m->code, 'parent' => $make->code, 'label' => ['en' => $m->name, 'fr' => $m->name]])->values()->all() : [];
        }
        $needle = MasterDataNormalizer::normalize($q);
        $legacy = array_flip(self::LEGACY_CODES[$code] ?? []);

        return VehicleReferenceValue::where(['group' => $code, 'active' => true])->orderBy('sort_order')->get()
            ->map(fn ($v) => ['code' => $v->code, 'label' => ['en' => $v->label_en, 'fr' => $v->label_fr]] + (isset($legacy[$v->code]) ? ['aliases' => [$legacy[$v->code]]] : []))
            ->filter(fn ($v) => $needle === '' || str_contains(MasterDataNormalizer::searchText([$v['code'], $v['label']['en'], $v['label']['fr']]), $needle))
            ->take($limit)->values()
            ->push(['code' => 'OTHER', 'label' => ['en' => 'Other / Not listed', 'fr' => 'Autre / Non répertorié'], 'is_other' => true])->all();
    }

    public function exists(string $list, string $code, ?string $parent): bool
    {
        if (! self::available()) {
            return true; // vehicle master not installed: nothing to validate against
        }
        $group = self::listCode($list);

        return match ($group) {
            'makes' => VehicleMake::where('code', $code)->where('active', true)->exists(),
            'models' => VehicleModel::where('code', $code)->when($parent, fn ($q) => $q->whereHas('make', fn ($m) => $m->where('code', $parent)))->exists(),
            null => false,
            default => VehicleReferenceValue::where(['group' => $group, 'code' => self::canonical($group, $code), 'active' => true])->exists(),
        };
    }
}
