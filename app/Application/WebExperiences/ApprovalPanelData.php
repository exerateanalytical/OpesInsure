<?php

declare(strict_types=1);

namespace App\Application\WebExperiences;

use App\Application\Approvals\ApprovalService;
use App\Models\{ApprovalRequest, User};

/**
 * Read-only view model for the shared approval panel
 * (resources/views/filament/shared/approval-panel.blade.php): maker, checker,
 * requested change, reason, evidence, before/after, and why the viewer cannot
 * decide (self-approval + ApprovalService blockers). Never decides anything.
 */
final class ApprovalPanelData
{
    public function __construct(private readonly ApprovalService $approvals) {}

    /** @return array<string, mixed> */
    public function for(ApprovalRequest $req, ?User $viewer): array
    {
        $payload = (array) ($req->payload ?? []);
        $context = (array) ($req->context ?? []);
        $self = $viewer !== null && $req->requested_by === $viewer->id;
        $blockers = $viewer && $req->status === 'PENDING' && ! $self ? $this->approvals->blockers($req, $viewer) : [];

        return [
            'status' => (string) $req->status,
            'change' => $req->action_code.' · '.$req->subject_type,
            'maker' => $req->requester?->full_name,
            'checker' => $req->decider?->full_name,
            'requested_at' => $req->created_at?->toDateTimeString(),
            'decided_at' => $req->decided_at?->toDateTimeString(),
            'amount' => $req->amount !== null ? Money::display((int) round(((float) $req->amount) * 100), $req->currency ?: 'XAF') : null,
            'reason' => $req->reason,
            'evidence' => self::evidence($payload, $context),
            'diff' => self::diff($payload),
            'self_approval' => $self && $req->status === 'PENDING',
            'blockers' => array_values(array_map('strval', $blockers)),
        ];
    }

    /** @return list<string> evidence references recorded with the request. */
    private static function evidence(array $payload, array $context): array
    {
        $out = [];
        foreach ([$payload, $context] as $bag) {
            foreach (['evidence', 'evidence_reference', 'evidence_references', 'document_id', 'document_ids'] as $k) {
                foreach ((array) ($bag[$k] ?? []) as $v) {
                    if (is_scalar($v) && $v !== '') {
                        $out[] = (string) $v;
                    }
                }
            }
        }

        return array_values(array_unique($out));
    }

    /** @return list<array{field:string,before:?string,after:?string}> */
    private static function diff(array $payload): array
    {
        $before = (array) ($payload['before'] ?? []);
        $after = (array) ($payload['after'] ?? (isset($payload['before']) ? [] : array_diff_key($payload, array_flip(['evidence', 'evidence_reference', 'evidence_references', 'document_id', 'document_ids']))));
        $rows = [];
        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $field) {
            $b = $before[$field] ?? null;
            $a = $after[$field] ?? null;
            if ($b === $a) {
                continue;
            }
            $rows[] = ['field' => (string) $field, 'before' => self::str($b), 'after' => self::str($a)];
        }

        return $rows;
    }

    private static function str(mixed $v): ?string
    {
        return $v === null ? null : (is_scalar($v) ? (is_bool($v) ? ($v ? 'true' : 'false') : (string) $v) : (string) json_encode($v, JSON_UNESCAPED_UNICODE));
    }
}
