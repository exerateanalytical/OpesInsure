<?php

declare(strict_types=1);

namespace App\Application\Underwriting\Proposal;

use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use App\Application\Shared\CanonicalJson;
use App\Models\Proposal;
use App\Models\ProposalDeclaration;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * REQ-PRP-002 — declarations / attestations / consent on a proposal, append-only (proposal_declarations), each with
 * market-conduct evidence (ICE gap 3): the statement text hash + version, the answers hash, the questionnaire hash and
 * the terms hash the proposer saw, channel, IP and user agent. Statements come from config('proposals.declarations')
 * and stay legal_status UNVERIFIED until counsel approves a version.
 */
final class ProposalDeclarations
{
    public function __construct(private readonly CanonicalJson $json, private readonly AuditWriter $audit, private readonly OutboxWriter $outbox) {}

    /** @return array<string, array<string,mixed>> */
    public function catalogue(): array
    {
        return config('proposals.declarations', []);
    }

    /** @return list<string> codes required before submission */
    public function requiredCodes(): array
    {
        return array_keys(array_filter($this->catalogue(), fn ($d) => (bool) ($d['required_for_submit'] ?? false)));
    }

    /** Current-version acceptance of $code on this proposal. */
    public function accepted(Proposal $p, string $code): ?ProposalDeclaration
    {
        $def = $this->catalogue()[$code] ?? null;

        if ($def === null) {
            return null;
        }

        return $this->current($p)->where('code', $code)->where('text_version', $def['version'])->last();
    }

    /** @return list<string> required codes without a current acceptance */
    public function missing(Proposal $p): array
    {
        return array_values(array_filter($this->requiredCodes(), fn ($c) => $this->accepted($p, $c) === null));
    }

    public function accept(Proposal $p, string $code, ?User $actor, string $channel = 'API', array $context = []): ProposalDeclaration
    {
        $def = $this->catalogue()[$code] ?? null;
        if ($def === null) {
            throw ValidationException::withMessages(['declarations' => "Unknown declaration {$code}."]);
        }
        $response = $p->disclosureResponse()->first();
        $row = ProposalDeclaration::create([
            'proposal_id' => $p->id, 'code' => $code, 'text_version' => $def['version'], 'text_hash' => $this->json->hash($def['statement']),
            'statement' => $def['statement'], 'legal_status' => $def['legal_status'] ?? 'UNVERIFIED_LEGAL_WORDING', 'channel' => strtoupper($channel),
            'evidence' => array_filter([
                'answers_hash' => $response?->answers_hash,
                'question_snapshot_hash' => $p->question_snapshot_hash,
                'terms_hash' => $this->json->hash($p->terms_snapshot ?? []),
                'proposal_version' => $p->version,
                'ip' => $context['ip'] ?? null,
                'user_agent' => isset($context['user_agent']) ? mb_substr((string) $context['user_agent'], 0, 255) : null,
            ], fn ($v) => $v !== null),
            'accepted_by' => $actor?->id, 'accepted_at' => now(),
        ]);
        $this->audit->record('proposal.declaration.accepted', 'proposal', $p->id, ['code' => $code, 'text_version' => $def['version'], 'declaration_id' => $row->id]);
        $this->outbox->record('proposal.declaration.accepted', 'proposal', $p->id, ['proposal_id' => $p->id, 'code' => $code, 'declaration_id' => $row->id]);

        return $row;
    }

    /** @return list<array<string,mixed>> declarations accepted in the current answering round, for snapshots */
    public function summary(Proposal $p): array
    {
        return $this->current($p)
            ->map(fn (ProposalDeclaration $d) => ['code' => $d->code, 'text_version' => $d->text_version, 'text_hash' => $d->text_hash,
                'legal_status' => $d->legal_status, 'accepted_at' => $d->accepted_at?->toIso8601String(), 'accepted_by' => $d->accepted_by])->values()->all();
    }

    /** Declarations only count for the answers they were given on: answering again requires a fresh attestation. */
    private function current(Proposal $p): \Illuminate\Support\Collection
    {
        $hash = $p->disclosureResponse()->value('answers_hash');

        return ProposalDeclaration::where('proposal_id', $p->id)->orderBy('accepted_at')->get()
            ->filter(fn (ProposalDeclaration $d) => ($d->evidence['answers_hash'] ?? null) === $hash)->values();
    }
}
