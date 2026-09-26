<?php

declare(strict_types=1);

namespace App\Application\OperationsTaxonomy;

use App\Application\DataReadiness\DataReadinessRegistry;
use App\Application\DataReadiness\DataStatus;
use App\Application\PrivateOnboarding\PrivateOnboardingTemplates;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Registers the Gap Closure Pack files 10 + 11 items (taxonomies and production gates) in the Data Readiness registry. */
final class OperationsReadiness
{
    public const SOURCE = 'GAP_CLOSURE_PACK_2026';

    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;
        DataReadinessRegistry::extend('case_management', fn () => [
            ...self::lists(['task_types', 'queue_types', 'escalation_reasons', 'closure_reasons'], 'case_management'),
            self::slaProfiles(),
        ]);
        DataReadinessRegistry::extend('complaints', fn () => self::lists(['complaint_categories', 'complaint_resolution_reasons'], 'complaints'));
        DataReadinessRegistry::extend('notifications', fn () => [...self::lists(['notification_events'], 'notifications'), self::templates()]);
        DataReadinessRegistry::extend('documents', fn () => [
            ...self::lists(['retention_classes', 'revocation_reasons', 'replacement_reasons', 'duplicate_reasons'], 'documents'),
            self::retention(), self::numbering(), self::signatories(),
        ]);
        foreach (PrivateOnboardingTemplates::WIRING as $dataset => [, , , , $domain]) {
            DataReadinessRegistry::extend($domain, fn () => [self::dataset($dataset, $domain)]);
        }
    }

    private static function row(string $domain, string $item, ?string $declared, string $status, array $missing, string $evidence, string $source): array
    {
        return app(DataReadinessRegistry::class)->row($domain, $item, $declared, $status, $missing, $evidence, $source);
    }

    /** @return list<array<string, mixed>> */
    private static function lists(array $keys, string $domain): array
    {
        $out = [];
        foreach ($keys as $key) {
            $n = count(OperationsCatalogue::list($key));
            $out[] = self::row($domain, 'gap_'.$key, 'PLATFORM_NORMALIZED', $n > 0 ? DataStatus::PLATFORM_NORMALIZED : DataStatus::CONFIG_REQUIRED,
                $n > 0 ? [] : ['Empty in the pack'], "{$n} codes, enforced by OperationsCatalogue", OperationsCatalogue::REF);
        }

        return $out;
    }

    private static function slaProfiles(): array
    {
        $n = Schema::hasTable('sla_policy_overrides') ? DB::table('sla_policy_overrides')->where('status', 'ACTIVE')->where('deadline_label', 'PLATFORM_SLA')->count() : 0;

        return self::row('case_management', 'gap_sla_profiles', OperationsCatalogue::gateStatus('sla_profiles'), $n > 0 ? DataStatus::VERIFIED : DataStatus::CONFIG_REQUIRED,
            $n > 0 ? [] : ['No tenant PLATFORM_SLA profile configured (import target sla_profiles)'], "{$n} ACTIVE PLATFORM_SLA override(s)", 'sla_policy_overrides');
    }

    private static function templates(): array
    {
        $events = OperationsCatalogue::list('notification_events');
        $active = Schema::hasColumn('notification_templates', 'event_code')
            ? DB::table('notification_templates')->where('status', 'ACTIVE')->whereIn('event_code', $events)->distinct()->pluck('event_code')->all() : [];
        $missing = array_values(array_diff($events, $active));

        return self::row('notifications', 'gap_notification_templates', 'PLATFORM_NORMALIZED', $missing === [] ? DataStatus::VERIFIED : DataStatus::CONFIG_REQUIRED,
            array_map(fn ($e) => "{$e} has no ACTIVE template (seeded DRAFT awaits approval)", $missing), count($active).'/'.count($events).' events with an ACTIVE template', 'notification_templates');
    }

    private static function retention(): array
    {
        $classes = OperationsCatalogue::list('retention_classes');
        $active = Schema::hasColumn('retention_schedules', 'retention_class')
            ? DB::table('retention_schedules')->where('status', 'ACTIVE')->whereNotNull('legal_basis')->whereIn('retention_class', $classes)->distinct()->pluck('retention_class')->all() : [];
        $missing = array_values(array_diff($classes, $active));

        return self::row('documents', 'gap_retention_schedules', OperationsCatalogue::gateStatus('retention'), $missing === [] ? DataStatus::VERIFIED : DataStatus::PENDING_SOURCE,
            array_map(fn ($c) => "{$c}: no approved legally-validated retention period", $missing), count($active).'/'.count($classes).' classes with an ACTIVE schedule', 'retention_schedules');
    }

    private static function numbering(): array
    {
        $n = Schema::hasColumn('document_numbering_families', 'approval_status')
            ? DB::table('document_numbering_families')->where('approval_status', 'APPROVED')->count() : 0;

        return self::row('documents', 'gap_document_numbering_profiles', OperationsCatalogue::gateStatus('document_numbering_profiles'), $n > 0 ? DataStatus::VERIFIED : DataStatus::CONFIG_REQUIRED,
            $n > 0 ? [] : ['No tenant-approved numbering profile (document_numbering_families.approval_status)'], "{$n} approved numbering profile(s)", 'document_numbering_families');
    }

    private static function signatories(): array
    {
        $n = Schema::hasTable('signatory_authorities') ? DB::table('signatory_authorities')->where('status', 'VERIFIED')->count() : 0;

        return self::row('documents', 'gap_signatory_authority_registry', OperationsCatalogue::gateStatus('signatory_authority_registry'), $n > 0 ? DataStatus::VERIFIED : DataStatus::PENDING_SOURCE,
            $n > 0 ? [] : ['No verified signatory mandate (import SIGNATORY_MANDATES)'], "{$n} verified signatory authorit(ies)", 'signatory_authorities');
    }

    private static function dataset(string $dataset, string $domain): array
    {
        $counts = Schema::hasTable('tenant_onboarding_records')
            ? DB::table('tenant_onboarding_records')->where('dataset', $dataset)->selectRaw('review_status, count(*) n')->groupBy('review_status')->pluck('n', 'review_status') : collect();
        $accepted = (int) ($counts['ACCEPTED'] ?? 0);
        $gate = PrivateOnboardingTemplates::dataset($dataset)['production_gate'];

        return self::row($domain, 'private_'.strtolower($dataset), 'PENDING_PRIVATE_SOURCE', $accepted > 0 ? DataStatus::UNVERIFIED : DataStatus::PENDING_SOURCE,
            $accepted > 0 ? ['Accepted source evidence not yet promoted by the owning domain'] : [$gate], "{$accepted} accepted / ".(int) ($counts['RECEIVED'] ?? 0).' received onboarding record(s)', 'tenant_onboarding_records');
    }
}
