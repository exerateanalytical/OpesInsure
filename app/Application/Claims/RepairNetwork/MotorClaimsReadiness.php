<?php

declare(strict_types=1);

namespace App\Application\Claims\RepairNetwork;

use App\Application\Claims\Taxonomy\ClaimTaxonomy;
use App\Application\DataReadiness\DataReadinessRegistry;
use App\Application\DataReadiness\DataStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agent GP3 — computed Data Readiness rows for gap pack 03 (claims taxonomies, repair network, vehicle technical / fiscal power).
 * A gate is production-usable only when the data actually exists with a production status; declared statuses never promote themselves.
 */
final class MotorClaimsReadiness
{
    public static function register(): void
    {
        DataReadinessRegistry::extend('claims', fn (array $s) => self::claims());
        DataReadinessRegistry::extend('repair_network', fn (array $s) => self::repairNetwork());
        DataReadinessRegistry::extend('vehicles', fn (array $s) => self::vehicles());
    }

    private static function reg(): DataReadinessRegistry
    {
        return app(DataReadinessRegistry::class);
    }

    /** @return list<array<string, mixed>> */
    public static function claims(): array
    {
        $items = ['cause_of_loss' => ['cause_of_loss'], 'damage_taxonomy' => ['motor_damage_area', 'damage_severity'], 'injury_taxonomy' => ['injury_severity'],
            'evidence_type' => ['evidence_type'], 'decision_reason' => ['decision_reason'], 'rejection_reason' => ['rejection_reason']];
        $out = [];
        foreach ($items as $item => $lists) {
            $counts = [];
            foreach ($lists as $l) {
                $counts[$l] = DB::table('master_data_values')->where(['domain_code' => 'claims', 'list_code' => $l, 'status' => 'ACTIVE'])->whereNull('tenant_id')->count();
            }
            $empty = array_keys(array_filter($counts, fn ($n) => $n === 0));
            $out[] = self::reg()->row('claims', $item, 'POPULATED', $empty === [] ? DataStatus::PLATFORM_NORMALIZED : DataStatus::PENDING_SOURCE,
                array_map(fn ($l) => "claims.$l not seeded (php artisan opesinsure:seed-master-data)", $empty),
                collect($counts)->map(fn ($n, $l) => "claims.$l: $n values")->join(', ').' (gap pack 03)', ClaimTaxonomy::SOURCE);
        }
        $reasons = Schema::hasColumn('claim_decision_reason_codes', 'reason_group') ? DB::table('claim_decision_reason_codes')->whereNotNull('reason_group')->count() : 0;
        $out[] = self::reg()->row('claims', 'decision_reason_codes', 'POPULATED', $reasons > 0 ? DataStatus::PLATFORM_NORMALIZED : DataStatus::PENDING_SOURCE,
            $reasons > 0 ? [] : ['Pack decision / rejection reasons not merged into claim_decision_reason_codes'], "$reasons pack reason codes usable by the decision endpoint", 'claim_decision_reason_codes');

        return $out;
    }

    /** @return list<array<string, mixed>> */
    public static function repairNetwork(): array
    {
        return [
            self::registerRow('garage_master', 'GARAGE', 'PENDING_INSURER_NETWORK_SOURCE', 'Insurer garage network not imported: import target repair_garages'),
            self::registerRow('adjuster_expert_master', 'EXPERT', 'PENDING_OFFICIAL_IMPORT', 'DGTCFM technical experts list not imported: import target technical_experts'),
        ];
    }

    private static function registerRow(string $item, string $category, string $declared, string $missing): array
    {
        $q = DB::table('provider_profiles')->where('category', $category);
        $pending = (clone $q)->whereIn('data_status', RepairNetworkService::PENDING)->count();
        $usable = (clone $q)->where(fn ($w) => $w->whereNull('data_status')->orWhereNotIn('data_status', RepairNetworkService::PENDING))->count();
        $status = $usable === 0 ? DataStatus::PENDING_SOURCE : ($pending > 0 ? DataStatus::UNVERIFIED : DataStatus::VERIFIED);

        return self::reg()->row('repair_network', $item, $declared, $status,
            array_merge($usable === 0 ? [$missing] : [], $pending > 0 ? ["$pending imported entries awaiting source verification"] : []),
            "$usable usable / $pending pending $category providers (provider_profiles)", 'provider_profiles');
    }

    /** @return list<array<string, mixed>> */
    public static function vehicles(): array
    {
        if (! Schema::hasTable('vehicle_fiscal_power_records')) {
            return [];
        }
        $r = self::reg();
        $variants = DB::table('vehicle_variants')->count();
        $power = DB::table('vehicle_power_specs')->where('status', 'VERIFIED')->distinct()->count('variant_id');
        $fiscal = DB::table('vehicle_fiscal_power_records')->where('review_state', 'VERIFIED')->count();
        $conflicts = DB::table('vehicle_fiscal_power_conflicts')->where('status', 'OPEN')->count();
        $schedules = DB::table('vehicle_stamp_duty_rate_schedules')->where('status', 'APPROVED')->distinct()->count('schedule_code');

        return [
            $r->row('vehicles', 'full_variant_population', 'PENDING_OFFICIAL_OR_LICENSED_SOURCE', $variants > 0 && $power >= $variants ? DataStatus::VERIFIED : DataStatus::PENDING_SOURCE,
                $power >= $variants && $variants > 0 ? [] : ['Official or licensed variant dataset (21 technical fields) not loaded: import targets vehicle_generations / vehicle_variants'],
                "$variants variants, $power with verified technical power", 'vehicle_variants'),
            $r->row('vehicles', 'fiscal_power_cv', 'PENDING_FISCAL_POWER_VERIFICATION', $fiscal === 0 ? DataStatus::PENDING_SOURCE : ($conflicts > 0 ? DataStatus::UNVERIFIED : DataStatus::VERIFIED),
                array_merge($fiscal === 0 ? ['No verified puissance administrative (CIVIC / carte grise / authority data); quotes needing it route to verification'] : [],
                    $conflicts > 0 ? ["$conflicts open fiscal power conflicts"] : []),
                "$fiscal verified fiscal power records; no automatic hp-to-CV conversion (VPWR-006)", 'vehicle_fiscal_power_records'),
            $r->row('vehicles', 'automobile_stamp_duty_rates', 'VERIFIED_CURRENT_RULE_BASELINE', $schedules >= 2 ? DataStatus::VERIFIED : DataStatus::CONFIG_REQUIRED,
                $schedules >= 2 ? [] : ['Seeded DRAFT stamp duty schedules must be approved by a checker (maker-checker) before rating applies them'],
                "$schedules of 2 schedules APPROVED", 'vehicle_stamp_duty_rate_schedules'),
        ];
    }
}
