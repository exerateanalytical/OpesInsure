<?php

declare(strict_types=1);

namespace App\Application\CarrierOperations\Setup;

use App\Application\Audit\AuditWriter;
use App\Application\CarrierOperations\Setup\Models\SetupChecklistAttestation;
use App\Domain\Shared\Clock\Clock;
use App\Models\User;
use Closure;
use Illuminate\Validation\ValidationException;

/**
 * REQ-SET-002 / REQ-SET-003 — shared activation-checklist evaluator.
 *
 * An item is AUTO (evaluated from platform data), MANUAL (attested by a user with evidence)
 * or CONDITIONAL (only applies when `applies` says so, e.g. tariffs only when the insurer's
 * RATING capability is configured — AOM: configured-mode prerequisites apply only to
 * capabilities set to configured modes). Data checks can never be overridden by attestation
 * unless the item explicitly allows NOT_APPLICABLE.
 *
 * Item definition keys: code, label, check?: Closure(): bool, applies?: Closure(): bool,
 * attest?: bool, na?: bool, evidence?: bool, system?: bool (set by the lifecycle itself).
 */
final class SetupChecklist
{
    public function __construct(private readonly AuditWriter $audit, private readonly Clock $clock) {}

    /**
     * @param  list<array<string,mixed>>  $items
     * @return list<array{order:int,code:string,label:string,kind:string,status:string,source:string,evidence_reference:?string,notes:?string}>
     */
    public function evaluate(string $setupType, string $setupId, array $items): array
    {
        $attest = SetupChecklistAttestation::where(['setup_type' => $setupType, 'setup_id' => $setupId])->get()->keyBy('item_code');
        $out = [];
        foreach (array_values($items) as $i => $item) {
            $a = $attest->get($item['code']);
            $kind = isset($item['system']) ? 'SYSTEM' : (isset($item['applies']) ? 'CONDITIONAL' : (isset($item['check']) ? 'AUTO' : 'MANUAL'));
            $status = 'PENDING';
            $source = 'NONE';
            if (isset($item['applies']) && ! ($item['applies'])()) {
                [$status, $source] = ['NOT_APPLICABLE', 'CAPABILITY_PROFILE'];
            } elseif (isset($item['check']) && ($item['check'])()) {
                [$status, $source] = ['COMPLETE', 'AUTO'];
            } elseif ($a !== null && $a->status !== 'PENDING' && ($a->status === 'COMPLETE' ? ! isset($item['check']) : ($item['na'] ?? false))) {
                [$status, $source] = [$a->status, 'ATTESTATION'];
            }
            $out[] = ['order' => $i + 1, 'code' => $item['code'], 'label' => $item['label'], 'kind' => $kind, 'status' => $status, 'source' => $source,
                'evidence_reference' => $a?->evidence_reference, 'notes' => $a?->notes, 'attestable' => $this->attestable($item), 'not_applicable_allowed' => (bool) ($item['na'] ?? false)];
        }

        return $out;
    }

    /** @param list<array> $evaluated @param list<string>|null $codes @return list<string> unmet item codes */
    public static function unmet(array $evaluated, ?array $codes = null): array
    {
        return array_values(array_map(fn ($r) => $r['code'], array_filter($evaluated,
            fn ($r) => ($codes === null || in_array($r['code'], $codes, true)) && ! in_array($r['status'], ['COMPLETE', 'NOT_APPLICABLE'], true))));
    }

    /** @param list<array<string,mixed>> $items */
    public function attest(string $setupType, string $setupId, array $items, string $code, string $status, ?string $evidence, ?string $notes, User $actor): SetupChecklistAttestation
    {
        $item = collect($items)->firstWhere('code', $code);
        if ($item === null) {
            throw ValidationException::withMessages(['item' => "Unknown checklist item {$code}."]);
        }
        if ($status === 'NOT_APPLICABLE' && ! ($item['na'] ?? false)) {
            throw ValidationException::withMessages(['status' => "{$code} cannot be marked NOT_APPLICABLE."]);
        }
        if ($status === 'COMPLETE' && ! $this->attestable($item)) {
            throw ValidationException::withMessages(['item' => "{$code} is evaluated from platform data and cannot be attested manually."]);
        }
        if ($status === 'NOT_APPLICABLE' && trim((string) $notes) === '') {
            throw ValidationException::withMessages(['notes' => 'A reason is required to mark an item NOT_APPLICABLE.']);
        }
        if ($status === 'COMPLETE' && ($item['evidence'] ?? false) && trim((string) $evidence) === '') {
            throw ValidationException::withMessages(['evidence_reference' => "{$code} requires an evidence reference."]);
        }
        $row = SetupChecklistAttestation::firstOrNew(['setup_type' => $setupType, 'setup_id' => $setupId, 'item_code' => $code]);
        $old = $row->exists ? ['status' => $row->status, 'evidence_reference' => $row->evidence_reference] : [];
        $row->fill(['status' => $status, 'evidence_reference' => $evidence, 'notes' => $notes, 'recorded_by' => $actor->id, 'recorded_at' => $this->clock->now()])->save();
        $this->audit->recordChange('setup.checklist.attested', strtolower($setupType).'_setup', $setupId, $old, ['item' => $code, 'status' => $status, 'evidence_reference' => $evidence], 'CHECKLIST_ATTESTATION');

        return $row;
    }

    private function attestable(array $item): bool
    {
        return ! isset($item['check']) && ! isset($item['system']) && ($item['attest'] ?? true);
    }
}
