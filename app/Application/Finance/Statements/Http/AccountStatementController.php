<?php

declare(strict_types=1);

namespace App\Application\Finance\Statements\Http;

use App\Application\Finance\Statements\AccountStatementService;
use App\Application\Identity\PartyResolver;
use App\Domain\Tenancy\TenantContext;
use App\Models\PartnerStatement;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Batch 9-8 — REQ-PAY-015 account statements (JSON, or PDF with ?format=pdf). Read-only. */
final class AccountStatementController
{
    public function __construct(private TenantContext $tenant, private AccountStatementService $statements) {}

    /** Staff: GET finance/statements/{subjectType}/{subject}?from=&to=&currency= */
    public function show(string $subjectType, string $subject, Request $r): Response
    {
        $d = $this->period($r);

        return $this->respond($this->statements->build($this->tenant->id(), $subjectType, $subject, $d['from'], $d['to'], $d['currency']), $r);
    }

    /** Staff: the persisted (Wave 6) partner statement in the same shape. */
    public function partnerStatement(string $statement, Request $r): Response
    {
        $s = PartnerStatement::where('tenant_id', $this->tenant->id())->findOrFail($statement);

        return $this->respond($this->statements->fromPartnerStatement($s), $r);
    }

    /** Customer (mobile): own statement only — the subject is always the caller's party. */
    public function mine(Request $r, PartyResolver $parties): Response
    {
        $d = $this->period($r);
        $party = $parties->forUser($r->user());
        abort_if(! $party, 404);

        return $this->respond($this->statements->build($this->tenant->id(), 'customer', $party->id, $d['from'], $d['to'], $d['currency']), $r);
    }

    /** @return array{from:string,to:string,currency:string} */
    private function period(Request $r): array
    {
        $d = $r->validate(['from' => 'nullable|date', 'to' => 'nullable|date', 'currency' => 'nullable|string|size:3', 'format' => 'nullable|in:json,pdf']);

        return [
            'from' => $d['from'] ?? now()->startOfMonth()->toDateString(),
            'to' => $d['to'] ?? now()->toDateString(),
            'currency' => $d['currency'] ?? 'XAF',
        ];
    }

    private function respond(array $statement, Request $r): Response
    {
        if ($r->query('format') !== 'pdf') {
            return response()->json(['data' => $statement]);
        }
        $m = fn ($v) => number_format(((int) $v) / 100, 0, '.', ' ');
        $lines = array_map(fn ($l) => \Carbon\Carbon::parse($l['occurred_at'])->format('d/m/Y').' · '.$l['line_type'].' · '.$l['description'].' · '.$m($l['amount_minor']).' · '.$m($l['balance_minor']), $statement['lines']);
        // D3: canonical secure shell (on-demand statement: no registry verification code).
        $bytes = app(\App\Application\Documents\Engine\SecureShellRenderer::class)->render([
            'type_code' => 'CUSTOMER_STATEMENT', 'number' => (string) $statement['statement_number'], 'issuer_name' => 'OpesInsure', 'currency' => $statement['currency'],
            'title_en' => 'Account statement', 'title_fr' => 'Relevé de compte', 'label' => $statement['subject']['type'].' · '.($statement['subject']['name'] ?? '—'),
            'values' => ['party.name' => $statement['subject']['name'] ?? '—'],
            'sections' => [
                ['heading' => 'Période / Period', 'paragraphs' => [$statement['period_start'].' → '.$statement['period_end'].' · '.$statement['currency'], 'Solde d\'ouverture / Opening balance: '.$m($statement['opening_balance_minor'])]],
                ['heading' => 'Mouvements / Transactions', 'paragraphs' => $lines !== [] ? $lines : ['Aucun mouvement / No transactions in this period.']],
                ['heading' => 'Solde de clôture / Closing balance', 'paragraphs' => [$m($statement['closing_balance_minor']).' '.$statement['currency'].' ('.($statement['balance_meaning'] === 'OWED_BY_SUBJECT' ? 'amount due by you' : 'amount due to you').')']],
            ],
            'template_ref' => 'SYSTEM account statement', 'hash_basis' => $statement['content_hash'] ?? null,
        ]);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$statement['statement_number'].'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
