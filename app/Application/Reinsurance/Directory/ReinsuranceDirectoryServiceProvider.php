<?php

declare(strict_types=1);

namespace App\Application\Reinsurance\Directory;

use App\Application\DataReadiness\DataReadinessRegistry;
use App\Application\DataReadiness\DataStatus;
use App\Application\FinancialDistribution\ReinsuranceReference;
use App\Application\Import\ImportTargetRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

/**
 * Gap Closure Pack v1 files 05 + 07: reinsurer directory import target, approved-security routes and the computed
 * Data Readiness rows for the reinsurance_coinsurance gates. The marine / agriculture / livestock / aviation lists are
 * master data (database/data/master_data/workflow_gap_closure_marine_agri_aviation_reinsurance_2026.json) whose gates
 * surface through master_data_workflow_statuses.
 */
final class ReinsuranceDirectoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->afterResolving(ImportTargetRegistry::class, fn (ImportTargetRegistry $r) => $r->register('reinsurers', ReinsurerDirectoryTarget::class));
    }

    public function boot(): void
    {
        if ($this->app->resolved(ImportTargetRegistry::class)) {
            $this->app->make(ImportTargetRegistry::class)->register('reinsurers', ReinsurerDirectoryTarget::class);
        }
        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group(base_path('routes/reinsurance_directory.php'));
        }
        DataReadinessRegistry::extend('reinsurance_coinsurance', fn (array $s): array => self::readiness());
    }

    /** @return list<array<string, mixed>> */
    public static function readiness(): array
    {
        $reg = app(DataReadinessRegistry::class);
        if (! Schema::hasColumn('reinsurers', 'approved_security_status')) {
            return [];
        }
        $out = [];
        foreach (['reinsurer_directory' => ['REINSURER', 'RETROCESSIONAIRE'], 'reinsurance_broker_directory' => ['REINSURANCE_BROKER']] as $item => $roles) {
            $total = DB::table('reinsurers')->whereIn('role', $roles)->count();
            $approved = DB::table('reinsurers')->whereIn('role', $roles)->whereIn('approved_security_status', ReinsuranceReference::APPROVED_SECURITY)->count();
            $out[] = $reg->row('reinsurance_coinsurance', $item, $item === 'reinsurer_directory' ? 'PENDING_CIMA_OR_TENANT_APPROVED_SOURCE' : 'PENDING_PRIVATE_OR_REGULATORY_SOURCE',
                $approved > 0 ? DataStatus::VERIFIED : DataStatus::PENDING_SOURCE,
                $approved > 0 ? [] : ['No approved-security entry: import (target reinsurers) and approve via POST v1/reinsurance/reinsurers/{id}/security'],
                "$approved of $total directory entries approved as security (tenant or CIMA source)", 'reinsurers');
        }
        $active = DB::table('reinsurance_treaty_versions')->where('status', 'ACTIVE')->count();
        $out[] = $reg->row('reinsurance_coinsurance', 'treaty_master', 'PENDING_PRIVATE_SOURCE', $active > 0 ? DataStatus::VERIFIED : DataStatus::CONFIG_REQUIRED,
            $active > 0 ? [] : ['No activated treaty version (tenant treaty slips not yet captured)'], "$active active treaty versions", 'reinsurance_treaty_versions');
        $co = DB::table('coinsurance_arrangements')->where('status', 'ACTIVE')->count();
        $out[] = $reg->row('reinsurance_coinsurance', 'coinsurance', 'PENDING_PRIVATE_SOURCE', $co > 0 ? DataStatus::VERIFIED : DataStatus::CONFIG_REQUIRED,
            $co > 0 ? [] : ['No active co-insurance arrangement'], "$co active co-insurance arrangements", 'coinsurance_arrangements');

        return $out;
    }
}
