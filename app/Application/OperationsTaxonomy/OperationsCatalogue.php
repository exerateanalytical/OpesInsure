<?php

declare(strict_types=1);

namespace App\Application\OperationsTaxonomy;

use App\Application\Cases\CaseProblem;

/**
 * Gap Closure Pack v1 file 10 (database/data/gap_closure_2026/10_operations_documents_cases_sla_retention_notifications.json):
 * the single source for the operations taxonomies. The pack file IS the catalogue (no second copy): lists are read from it,
 * served by GET /api/v1/operations/taxonomy for pickers, and enforced by the owning services (cases, complaints,
 * document status changes, retention schedules, SLA profile import).
 *
 * Gates carried by the pack (never invented here): retention durations (PENDING_LEGAL_VALIDATION), SLA targets
 * (CONFIG_REQUIRED — tenant PLATFORM_SLA only), document numbering (CONFIG_REQUIRED), signatory authority
 * (PENDING_PRIVATE_SOURCE), complaint regulatory deadlines (PENDING_VERIFICATION).
 */
final class OperationsCatalogue
{
    public const FILE = 'database/data/gap_closure_2026/10_operations_documents_cases_sla_retention_notifications.json';

    public const SOURCE = 'GAP_CLOSURE_PACK_2026';

    public const REF = '10_operations_documents_cases_sla_retention_notifications.json';

    /** Pack case families => canonical case_families code (the owner's fixed 10 families; SECURITY files under OPERATIONS). */
    public const FAMILY_ALIASES = ['FRAUD' => 'FRAUD_REVIEW', 'FINANCE' => 'FINANCE_EXCEPTION', 'SECURITY' => 'OPERATIONS'];

    /** list key => [pack path, master-data list code, label en, label fr] */
    public const LISTS = [
        'task_types' => ['case_taxonomy.task_types', 'case_task_type', 'Case task type', 'Type de tâche'],
        'queue_types' => ['case_taxonomy.queue_types', 'case_queue_type', 'Case queue type', 'Type de file'],
        'escalation_reasons' => ['case_taxonomy.escalation_reasons', 'case_escalation_reason', 'Escalation reason', "Motif d'escalade"],
        'closure_reasons' => ['case_taxonomy.closure_reasons', 'case_closure_reason', 'Case closure reason', 'Motif de clôture'],
        'complaint_categories' => ['complaints.categories', 'complaint_category', 'Complaint category', 'Catégorie de réclamation'],
        'complaint_resolution_reasons' => ['complaints.resolution_reasons', 'complaint_resolution_reason', 'Complaint resolution reason', 'Motif de résolution'],
        'notification_events' => ['notification_events', 'notification_event', 'Notification event', 'Événement de notification'],
        'revocation_reasons' => ['document_reasons.revocation', 'document_revocation_reason', 'Document revocation reason', 'Motif de révocation'],
        'replacement_reasons' => ['document_reasons.replacement', 'document_replacement_reason', 'Document replacement reason', 'Motif de remplacement'],
        'duplicate_reasons' => ['document_reasons.duplicate', 'document_duplicate_reason', 'Duplicate document reason', 'Motif de duplicata'],
        'retention_classes' => ['retention.classes', 'retention_class', 'Retention class', 'Classe de conservation'],
    ];

    /** Pack sections that are production gates => [status, readiness domain]. */
    public const GATES = [
        'retention' => 'retention.status',
        'sla_profiles' => 'sla_profiles.status',
        'document_numbering_profiles' => 'document_numbering_profiles.status',
        'signatory_authority_registry' => 'signatory_authority_registry.status',
        'complaint_regulatory_deadlines' => 'complaints.regulatory_deadlines_status',
    ];

    private static ?array $pack = null;

    public static function pack(): array
    {
        return self::$pack ??= json_decode((string) file_get_contents(base_path(self::FILE)), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return list<string> */
    public static function list(string $key): array
    {
        return array_values((array) data_get(self::pack(), self::LISTS[$key][0], []));
    }

    public static function gateStatus(string $gate): ?string
    {
        $v = data_get(self::pack(), self::GATES[$gate]);

        return is_string($v) ? $v : null;
    }

    /** @return list<string> */
    public static function fields(string $section): array
    {
        return array_values((array) data_get(self::pack(), $section.'.fields', []));
    }

    public static function has(string $key, ?string $value): bool
    {
        return $value !== null && in_array($value, self::list($key), true);
    }

    /** Null passes (optional field); an unknown code is a 422 in the shared case problem envelope. */
    public static function assert(string $key, ?string $value, string $field): void
    {
        if ($value !== null && ! self::has($key, $value)) {
            throw CaseProblem::make('CODE_NOT_IN_TAXONOMY', 422, "{$field} {$value} is not in the operations taxonomy.", ['field' => $field, 'allowed' => self::list($key)]);
        }
    }

    public static function canonicalFamily(string $packFamily): string
    {
        return self::FAMILY_ALIASES[$packFamily] ?? $packFamily;
    }

    /** Document status-change action => reason list. */
    public static function reasonListFor(string $action): string
    {
        return $action === 'REPLACE' ? 'replacement_reasons' : 'revocation_reasons';
    }
}
