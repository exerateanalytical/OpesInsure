<?php

declare(strict_types=1);

namespace App\Application\Finance\Statements\Http;

use App\Application\Finance\Statements\AccountStatementService;
use App\Application\Identity\PartyResolver;
use App\Domain\Tenancy\TenantContext;
use App\Models\PartnerStatement;
use Barryvdh\DomPDF\Facade\Pdf;
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
        $bytes = Pdf::loadView('pdf.account-statement', ['s' => $statement])->setPaper('a4')->output();

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$statement['statement_number'].'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
