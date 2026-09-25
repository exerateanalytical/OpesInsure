<?php

declare(strict_types=1);

namespace App\Application\Vehicles;

use App\Models\Vehicles\VehicleGeneration;
use App\Models\Vehicles\VehicleMake;
use App\Models\Vehicles\VehicleMakeAlias;
use App\Models\Vehicles\VehicleModel;
use App\Models\Vehicles\VehicleModelAlias;
use App\Models\Vehicles\VehicleReferenceValue;
use App\Models\Vehicles\VehicleVariant;
use Illuminate\Support\Collection;

/**
 * Read side of the vehicle master: autocomplete, model lists, reference
 * enumerations and alias resolution. Ranking is UX only (Cameroon / Chinese
 * priority lists) — nothing here feeds rating.
 */
final class VehicleCatalogueService
{
    public const MIN_MODEL_YEAR = 1950;

    /** @return array{min: int, max: int} */
    public static function modelYearRange(): array
    {
        return ['min' => self::MIN_MODEL_YEAR, 'max' => (int) now()->year + 1];
    }

    /**
     * @return array{items: Collection<int, VehicleMake>, total: int}
     */
    public function searchMakes(?string $q, ?string $segment, bool $chinese, int $limit): array
    {
        $needle = VehicleText::normalize($q);
        $query = VehicleMake::query()->where('active', true)->with('aliases')->withCount(['models' => fn ($m) => $m->where('active', true)]);

        if ($chinese) {
            $query->where('country_of_origin', 'CN');
        }
        if ($segment) {
            $query->whereIn('segment', [strtoupper($segment), 'MIXED']);
        }

        $makes = $query->get();

        if ($needle !== '') {
            $makes = $makes
                ->map(function (VehicleMake $make) use ($needle) {
                    $make->setAttribute('match_score', $this->matchScore($needle, $make->normalized_name, $make->aliases->pluck('normalized_alias')->push(VehicleText::normalize($make->code))));

                    return $make;
                })
                ->filter(fn (VehicleMake $m) => $m->match_score > 0);
        }

        $rankColumn = $chinese ? 'ui_rank_chinese' : 'ui_rank_cameroon';
        $sorted = $makes->sort(function (VehicleMake $a, VehicleMake $b) use ($rankColumn) {
            return [
                -($a->match_score ?? 0),
                $a->{$rankColumn} ?? PHP_INT_MAX,
                $a->market_priority === 'HIGH' ? 0 : 1,
                $a->cameroon_status === 'HISTORICAL' ? 1 : 0,
                mb_strtolower($a->name),
            ] <=> [
                -($b->match_score ?? 0),
                $b->{$rankColumn} ?? PHP_INT_MAX,
                $b->market_priority === 'HIGH' ? 0 : 1,
                $b->cameroon_status === 'HISTORICAL' ? 1 : 0,
                mb_strtolower($b->name),
            ];
        })->values();

        return ['items' => $sorted->take(max(1, min($limit, 200)))->values(), 'total' => $sorted->count()];
    }

    /** @return Collection<int, VehicleModel> */
    public function modelsFor(VehicleMake $make, ?string $q, bool $includeInactive = false): Collection
    {
        $needle = VehicleText::normalize($q);
        $models = VehicleModel::where('make_id', $make->id)
            ->when(! $includeInactive, fn ($m) => $m->where('active', true))
            ->with('aliases')
            ->get();

        if ($needle !== '') {
            $models = $models->map(function (VehicleModel $m) use ($needle) {
                $m->setAttribute('match_score', $this->matchScore($needle, $m->normalized_name, $m->aliases->pluck('normalized_alias')));

                return $m;
            })->filter(fn (VehicleModel $m) => $m->match_score > 0);
        }

        return $models->sort(fn (VehicleModel $a, VehicleModel $b) => [-($a->match_score ?? 0), $a->status === 'HISTORICAL' ? 1 : 0, mb_strtolower($a->name)]
            <=> [-($b->match_score ?? 0), $b->status === 'HISTORICAL' ? 1 : 0, mb_strtolower($b->name)])->values();
    }

    /** Exact (normalized) match on code, name or alias; follows merges. */
    public function resolveMake(?string $text): ?VehicleMake
    {
        $n = VehicleText::normalize($text);
        if ($n === '') {
            return null;
        }

        $make = VehicleMake::where('normalized_name', $n)->orderByDesc('active')->first()
            ?? VehicleMake::where('code', VehicleText::code((string) $text))->first()
            ?? VehicleMakeAlias::where('normalized_alias', $n)->first()?->make;

        $guard = 0;
        while ($make && $make->merged_into_id && $guard++ < 5) {
            $make = VehicleMake::find($make->merged_into_id);
        }

        return $make;
    }

    public function resolveModel(VehicleMake $make, ?string $text): ?VehicleModel
    {
        $n = VehicleText::normalize($text);
        if ($n === '') {
            return null;
        }

        return VehicleModel::where('make_id', $make->id)->where('normalized_name', $n)->first()
            ?? VehicleModel::where('code', $make->code.'_'.VehicleText::code((string) $text))->first()
            ?? VehicleModelAlias::where('make_id', $make->id)->where('normalized_alias', $n)->first()?->model
            ?? VehicleModel::where('code', (string) $text)->where('make_id', $make->id)->first();
    }

    /** @return array<string, mixed> */
    public function reference(): array
    {
        $values = VehicleReferenceValue::where('active', true)->orderBy('sort_order')->get()->groupBy('group');
        $out = [];
        foreach (VehicleReferenceLabels::GROUPS as $key => $group) {
            $out[$key] = ($values[$group] ?? collect())->map(fn (VehicleReferenceValue $v) => [
                'code' => $v->code,
                'label' => ['en' => $v->label_en, 'fr' => $v->label_fr],
            ])->values()->all();
        }
        $out['segments'] = array_map(fn ($c) => ['code' => $c, 'label' => ['en' => ucfirst(strtolower($c)), 'fr' => ['PASSENGER' => 'Tourisme', 'COMMERCIAL' => 'Utilitaire', 'MIXED' => 'Mixte'][$c]]], ['PASSENGER', 'COMMERCIAL', 'MIXED']);
        $out['model_years'] = self::modelYearRange();

        return $out;
    }

    /** CUST-007: active generations of a model, oldest first (empty until an admin/import adds them). */
    public function generationsFor(VehicleModel $model): Collection
    {
        return VehicleGeneration::where('model_id', $model->id)->where('active', true)
            ->withCount(['variants' => fn ($q) => $q->where('active', true)])
            ->orderByRaw('year_from IS NULL')->orderBy('year_from')->orderBy('name')->get();
    }

    /** Active variants of a generation; with $year, only those whose year range covers it. */
    public function variantsFor(VehicleGeneration $generation, ?int $year = null): Collection
    {
        return VehicleVariant::where('generation_id', $generation->id)->where('active', true)
            ->when($year, fn ($q) => $q
                ->where(fn ($w) => $w->whereNull('year_from')->orWhere('year_from', '<=', $year))
                ->where(fn ($w) => $w->whereNull('year_to')->orWhere('year_to', '>=', $year)))
            ->orderBy('name')->get();
    }

    /**
     * Model years a generation covers (newest first). Open-ended generations
     * run to next year; a generation without a start year yields [] (the
     * picker then asks for the year freely).
     *
     * @return array<int, int>
     */
    public function yearsFor(VehicleGeneration $generation): array
    {
        if (! $generation->year_from) {
            return [];
        }
        $max = self::modelYearRange()['max'];
        $to = min($generation->year_to ?? $max, $max);

        return $to >= $generation->year_from ? range($to, $generation->year_from) : [];
    }

    /** @return array<string, mixed> */
    public function presentGeneration(VehicleGeneration $generation): array
    {
        return [
            'code' => $generation->code,
            'name' => $generation->name,
            'year_from' => $generation->year_from,
            'year_to' => $generation->year_to,
            'body_type' => $generation->body_type,
            'variants_count' => (int) ($generation->variants_count ?? 0),
        ];
    }

    /** @return array<string, mixed> */
    public function presentVariant(VehicleVariant $variant): array
    {
        return $variant->only(['code', 'name', 'body_type', 'powertrain', 'hybrid_subtype', 'transmission', 'drive_type', 'engine_capacity_cc', 'year_from', 'year_to']) + [
            // Auto-populate block (config auto_populate_from_engine_variant); null = unknown, ask the user.
            'engine_label' => $variant->name,
            'specs' => [
                'power_hp' => $variant->power_hp,
                'power_kw' => $variant->power_kw !== null ? (float) $variant->power_kw : null,
                'displacement_cc' => $variant->engine_capacity_cc,
                'fuel_type' => $variant->powertrain,
                'transmission' => $variant->transmission,
                'drivetrain' => $variant->drive_type,
                'body_type' => $variant->body_type ?? $variant->generation?->body_type,
                'torque_nm' => $variant->torque_nm,
            ],
            'cylinders' => $variant->cylinders,
            'data_source' => $variant->data_source,
        ];
    }

    /** @return array<string, mixed> */
    public function presentMake(VehicleMake $make): array
    {
        return [
            'code' => $make->code,
            'name' => $make->name,
            'country_of_origin' => $make->country_of_origin,
            'segment' => $make->segment,
            'cameroon_status' => $make->cameroon_status,
            'market_priority' => $make->market_priority,
            'aliases' => $make->aliases->pluck('alias')->values()->all(),
            'models_count' => (int) ($make->models_count ?? $make->models()->where('active', true)->count()),
        ];
    }

    /** @return array<string, mixed> */
    public function presentModel(VehicleModel $model): array
    {
        return [
            'code' => $model->code,
            'name' => $model->name,
            'segment' => $model->segment,
            'status' => $model->status,
            'aliases' => $model->aliases->pluck('alias')->values()->all(),
        ];
    }

    /** 3 = exact, 2 = prefix, 1 = contains, 0 = no match (best over name and aliases). */
    private function matchScore(string $needle, string $name, Collection $aliases): int
    {
        $best = 0;
        foreach ($aliases->prepend($name) as $candidate) {
            $candidate = (string) $candidate;
            $score = match (true) {
                $candidate === $needle => 3,
                str_starts_with($candidate, $needle) => 2,
                strlen($needle) >= 3 && str_contains($candidate, $needle) => 1,
                default => 0,
            };
            $best = max($best, $score);
        }

        return $best;
    }
}
