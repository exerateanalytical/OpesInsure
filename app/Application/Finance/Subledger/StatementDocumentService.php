<?php

declare(strict_types=1);

namespace App\Application\Finance\Subledger;

use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use App\Application\Finance\Statements\AccountStatementService;
use App\Application\Documents\Engine\DocumentEngine;
use App\Application\Documents\Engine\DocumentNumberAllocator;
use App\Application\Documents\Engine\DocumentRegister;
use App\Models\Document;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Agent F1 — spec document_links DOC-193..200, generated through the document engine's numbering (DocumentNumberAllocator), type
 * register (DocumentRegister; the spec code is mapped to the catalogue's FINANCE.* canonical code, SubledgerCatalogue::STATEMENT_DOCUMENTS)
 * and PDF layout (canonical secure shell, SecureShellRenderer), stored as an engine Document (FINANCIAL group, VALID) whose provenance carries the spec
 * DOC number, the applied filters and a content hash of the figures. Figures come only from the sub-ledger read models — the
 * document is a rendering, never a source of truth.
 */
final class StatementDocumentService
{
    public const TRIGGER = 'FINANCE_SUBLEDGER_STATEMENT';

    public function __construct(private DocumentNumberAllocator $numbers, private DocumentRegister $register, private AuditWriter $audit, private OutboxWriter $outbox,
        private SubledgerViews $views, private SubledgerQuery $query, private SubledgerReports $reports, private AccountStatementService $statements) {}

    public function generate(string $tenantId, string $doc, string $subjectId, array $filters, string $actorId): Document
    {
        $map = SubledgerCatalogue::STATEMENT_DOCUMENTS[$doc] ?? throw ValidationException::withMessages(['document' => 'Only DOC-193..DOC-200 are sub-ledger statements.']);
        [$title, $figures, $sections] = $this->content($tenantId, $doc, $subjectId, $filters);
        $hash = hash('sha256', json_encode([$doc, $subjectId, $filters, $figures], JSON_THROW_ON_ERROR));
        if ($engine = $this->viaEngine($tenantId, $doc, $map, $subjectId, $filters, $title, $figures, $sections, $hash, $actorId)) {
            return $engine;
        }

        return $this->legacy($tenantId, $doc, $map, $subjectId, $filters, $actorId, $title, $figures, $sections);
    }

    /**
     * Launch review: DOC-193..200 from their published canonical templates through the document engine, in the
     * recipient's language (DOC-193: the customer's; otherwise the requesting user's), amounts in FCFA. One document per
     * (statement, subject, filters, figures): asking again for the same figures returns the same statement.
     */
    private function viaEngine(string $tenantId, string $doc, array $map, string $subjectId, array $filters, string $title, array $figures, array $sections, string $hash, string $actorId): ?Document
    {
        $cur = (string) ($filters['currency'] ?? 'XAF');
        $from = $filters['date_from'] ?? now()->startOfYear()->toDateString();
        $to = $filters['date_to'] ?? now()->toDateString();
        $int = fn (string ...$keys) => ($k = collect($keys)->first(fn ($k) => isset($figures[$k]) && is_int($figures[$k]))) !== null ? $figures[$k] : null;
        $name = fn (string $table, string $col = 'display_name') => DB::table($table)->where('id', $subjectId)->value($col);
        $carrierName = $doc === 'DOC-197' ? DB::table('carriers as c')->join('parties as p', 'p.id', '=', 'c.party_id')->where('c.id', $subjectId)->value('p.display_name') : null;
        $subjectName = match ($doc) {
            'DOC-193' => $name('parties'),
            'DOC-195', 'DOC-196', 'DOC-194' => $name('partners', 'legal_name') ?? $name('parties'),
            default => null,
        };
        $item = app(\App\Application\Documents\Engine\EventDocumentRouter::class)->issue('FINANCE_STATEMENT_GENERATED', $map['type'], [
            'tenant_id' => $tenantId, 'party_id' => $doc === 'DOC-193' ? $subjectId : null, 'carrier_id' => $doc === 'DOC-197' ? $subjectId : null, 'currency' => $cur,
            'issuer' => \App\Application\Documents\Engine\EventDocumentRouter::tenantIssuer($tenantId),
            'language' => $doc === 'DOC-193' ? \App\Application\Documents\Engine\EventDocumentRouter::partyLanguage($subjectId) : \App\Application\Documents\Engine\EventDocumentRouter::userLanguage($actorId),
            'subject' => ['type' => 'FIN_STATEMENT', 'key' => $subjectId.':'.substr($hash, 0, 16), 'label' => $doc.' '.$map['spec']],
            'label' => $doc.' '.$title, 'valid_from' => $filters['date_from'] ?? null, 'valid_until' => $filters['date_to'] ?? null,
            'provenance' => ['spec_document' => $doc, 'spec_code' => $map['spec'], 'applied_filters' => $filters, 'figures' => $figures, 'content_hash' => $hash],
            'fields' => array_filter([
                'statement.period' => \Carbon\Carbon::parse($from)->format('d/m/Y').' → '.\Carbon\Carbon::parse($to)->format('d/m/Y'),
                'policy.period' => \Carbon\Carbon::parse($from)->format('d/m/Y').' → '.\Carbon\Carbon::parse($to)->format('d/m/Y'),
                'policy.effective_from' => \Carbon\Carbon::parse($from)->toIso8601String(), 'policy.effective_until' => \Carbon\Carbon::parse($to)->toIso8601String(),
                'policy.currency' => $cur, 'party.name' => $doc === 'DOC-193' ? $subjectName : null, 'intermediary.name' => in_array($doc, ['DOC-195', 'DOC-196'], true) ? $subjectName : null,
                'statement.broker' => $doc === 'DOC-194' ? $subjectName : null, 'policy.insurer' => $carrierName,
                'statement.opening_balance' => $int('opening_balance'), 'statement.closing_balance' => $int('closing_balance', 'outstanding_amount', 'net_settlement'),
                'statement.collected_premium' => $int('payments', 'collections_minor'), 'statement.written_premium' => $int('premiums_minor', 'debits'),
                'refund.amount' => $int('refunds', 'refunds_minor'), 'statement.commission' => $int('commission_minor', 'gross_commission'),
                'statement.remittances' => $int('remittances_minor', 'paid_amount', 'premium_remitted'), 'statement.adjustments' => $int('adjustments', 'clawed_back', 'clawbacks_minor'),
                'settlement_batch.gross' => $int('gross_premium_payable'), 'settlement_batch.net' => $int('net_settlement'), 'settlement_batch.paid' => $int('settlements_paid'),
                'obligation.outstanding' => $int('premium_outstanding'), 'premium.taxes' => isset($figures['TAX_CLEARING']) ? (int) $figures['TAX_CLEARING'] + (int) ($figures['LEVY_CLEARING'] ?? 0) : null,
                'reconciliation.exceptions' => isset($figures['difference_minor']) ? \App\Application\Documents\Security\MappedFieldValues::money((int) $figures['difference_minor'], $cur) : null,
                'statement.items' => implode(' · ', array_merge(...array_map(fn ($s) => (array) $s['paragraphs'], $sections))) ?: null,
            ], fn ($v) => $v !== null && $v !== ''),
            'sections' => $sections,
        ]);
        if (($item['state'] ?? null) !== 'GENERATED' || ! ($d = Document::find($item['document_id']))) {
            return null;
        }
        $this->outbox->record('finance.statement.generated', 'document', $d->id, ['document_id' => $d->id, 'spec_document' => $doc, 'subject_id' => $subjectId, 'content_hash' => $hash]);

        return $d;
    }

    /** Former platform rendering: only when the engine cannot issue (no published template). */
    private function legacy(string $tenantId, string $doc, array $map, string $subjectId, array $filters, string $actorId, string $title, array $figures, array $sections): Document
    {
        $type = $this->register->describe($map['type']);
        $number = $this->numbers->allocate($tenantId, $map['type']);
        $verification = DocumentEngine::newVerificationCode();
        $hash = hash('sha256', json_encode([$doc, $subjectId, $filters, $figures], JSON_THROW_ON_ERROR));
        // D3: canonical secure shell.
        $bytes = app(\App\Application\Documents\Engine\SecureShellRenderer::class)->render([
            'type_code' => $map['type'], 'number' => $number['number'], 'verification' => $verification, 'lang' => 'EN',
            'issuer_name' => (string) DB::table('tenants')->where('id', $tenantId)->value('legal_name'), 'title_en' => $title, 'title_fr' => $title,
            'label' => $doc.' '.$map['spec'], 'subject' => ['type' => 'PARTY', 'key' => $subjectId, 'label' => $subjectId],
            'values' => array_filter(['policy.effective_from' => $filters['date_from'] ?? null, 'policy.effective_until' => $filters['date_to'] ?? null]),
            'sections' => $sections, 'status' => 'VALID', 'template_ref' => 'SYSTEM finance sub-ledger '.$doc, 'hash_basis' => $hash,
        ]);
        $key = 'documents/'.$tenantId.'/finance/'.$number['number'].'.pdf';
        Storage::disk((string) config('lifecycle.documents_disk', 'local'))->put($key, $bytes);

        $d = Document::create([
            'tenant_id' => $tenantId, 'party_id' => $doc === 'DOC-193' ? $subjectId : null, 'category' => 'ENGINE_'.$map['type'], 'storage_key' => $key, 'mime_type' => 'application/pdf',
            'size_bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'scan_status' => 'CLEAN', 'verification_status' => 'VERIFIED', 'ocr_data' => [],
            'document_type_code' => $map['type'], 'document_type_id' => $type['id'] ?? null, 'document_group' => $type['group_code'] ?? 'FINANCE',
            'subject_type' => 'FIN_STATEMENT', 'subject_key' => $subjectId, 'subject_label' => $doc.' '.$map['spec'], 'title' => $title, 'issuer_type' => 'INTERMEDIARY',
            'issuer_tenant_id' => $tenantId, 'language' => 'EN', 'document_origin' => 'SYSTEM', 'document_stage' => 'FINANCE', 'security_level' => $type['security_level'] ?? 'FINANCIAL_RESTRICTED',
            'status' => 'VALID', 'numbering_family' => $number['family'], 'document_number' => $number['number'], 'document_sequence' => $number['sequence'], 'verification_code' => $verification,
            'generation_trigger' => self::TRIGGER, 'issued_at' => now(), 'valid_from' => $filters['date_from'] ?? null, 'valid_until' => $filters['date_to'] ?? null,
            'provenance' => ['rendered_by' => 'OPESINSURE', 'spec_document' => $doc, 'spec_code' => $map['spec'], 'applied_filters' => $filters, 'figures' => $figures, 'content_hash' => $hash],
            'uploaded_by' => $actorId,
        ]);
        $this->audit->record('document.generated', 'document', $d->id, ['number' => $d->document_number, 'type' => $map['type'], 'spec_document' => $doc]);
        $this->outbox->record('finance.statement.generated', 'document', $d->id, ['document_id' => $d->id, 'spec_document' => $doc, 'subject_id' => $subjectId, 'content_hash' => $hash]);

        return $d;
    }

    /** @return array{0: string, 1: array<string, mixed>, 2: list<array{heading:string, paragraphs:list<string>}>} */
    private function content(string $tenantId, string $doc, string $subjectId, array $f): array
    {
        // Launch review: minor units (÷100), XAF printed as FCFA — the formatter of every issued document.
        $money = fn (int $m) => \App\Application\Documents\Security\MappedFieldValues::money($m, (string) ($f['currency'] ?? 'XAF'));
        $kv = fn (array $a) => array_map(fn ($k, $v) => str_replace('_', ' ', (string) $k).': '.(is_int($v) && $k !== 'settlements' ? $money($v) : (is_bool($v) ? ($v ? 'yes' : 'no') : json_encode($v))), array_keys($a), $a);
        $from = $f['date_from'] ?? now()->startOfYear()->toDateString();
        $to = $f['date_to'] ?? now()->toDateString();
        $cur = $f['currency'] ?? 'XAF';

        switch ($doc) {
            case 'DOC-193':
                $s = $this->views->customerStatement($tenantId, $subjectId, $f);
                $fig = array_intersect_key($s, array_flip(['opening_balance', 'debits', 'credits', 'payments', 'refunds', 'adjustments', 'closing_balance', 'reconciles']));

                return ['Customer account statement', $fig, [['heading' => 'Summary', 'paragraphs' => $kv($fig)],
                    ['heading' => 'Transactions', 'paragraphs' => array_map(fn ($l) => $l['occurred_at'].' '.$l['line_type'].' '.$money((int) $l['amount_minor']), $s['lines'])]]];
            case 'DOC-194':
                $a = $this->views->insurerBrokerAccount($tenantId, $subjectId, $f);
                $st = $this->statements->build($tenantId, 'broker', $subjectId, $from, $to, $cur);
                $fig = ['premiums_minor' => $a['premium_receivable']['gross_minor'], 'collections_minor' => $a['premium_receivable']['settled_minor'],
                    'commission_minor' => $a['commission_payable']['gross_minor'], 'commission_paid_minor' => $a['commission_payable']['paid_minor'],
                    'clawbacks_minor' => $a['commission_payable']['clawed_back_minor'], 'refunds_minor' => (int) ($st['totals_by_type']['REFUND'] ?? 0),
                    'remittances_minor' => $a['premium_receivable']['settled_minor'], 'opening_balance' => $st['opening_balance_minor'], 'closing_balance' => $st['closing_balance_minor'],
                    'reconciles' => $st['opening_balance_minor'] + array_sum($st['totals_by_type']) === $st['closing_balance_minor']];

                return ['Broker statement', $fig, [['heading' => 'Premium & commission (gross, not netted)', 'paragraphs' => $kv($fig)]]];
            case 'DOC-195':
            case 'DOC-196':
                $rows = $this->reports->run($tenantId, $doc === 'DOC-195' ? 'AGENT_COMMISSION_LEDGER' : 'BROKER_COMMISSION_LEDGER', ['agent_id' => $subjectId] + $f)['rows'];
                $fig = ['gross_commission' => array_sum(array_column($rows, 'gross_commission')), 'paid_amount' => array_sum(array_column($rows, 'paid_amount')),
                    'clawed_back' => array_sum(array_column($rows, 'clawed_back')), 'outstanding_amount' => array_sum(array_column($rows, 'outstanding_amount'))];

                return [$doc === 'DOC-195' ? 'Agent commission statement' : 'Broker commission statement', $fig, [['heading' => 'Summary', 'paragraphs' => $kv($fig)],
                    ['heading' => 'Commissions', 'paragraphs' => array_map(fn ($r) => $r['commission_id'].' '.$r['status'].' '.$money((int) $r['outstanding_amount']), $rows)]]];
            case 'DOC-197':
                $a = $this->views->brokerInsurerAccount($tenantId, $subjectId, $f);
                $settled = (int) DB::table('settlement_batches')->where('tenant_id', $tenantId)->where('carrier_id', $subjectId)->whereIn('status', ['PAID', 'SETTLED', 'RECONCILED'])->sum('net_amount_minor');
                $fig = ['gross_premium_payable' => $a['premium_payable']['gross_minor'], 'premium_remitted' => $a['premium_payable']['settled_minor'],
                    'premium_outstanding' => $a['premium_payable']['outstanding_minor'], 'commission_receivable' => $a['commission_receivable']['outstanding_minor'],
                    'net_settlement' => $a['net_due_to_insurer_minor'], 'settlements_paid' => $settled,
                    'reconciles' => $a['premium_payable']['outstanding_minor'] - $a['commission_receivable']['outstanding_minor'] === $a['net_due_to_insurer_minor']];

                return ['Carrier settlement statement', $fig, [['heading' => 'Gross obligations and net settlement', 'paragraphs' => $kv($fig)]]];
            case 'DOC-198':
            case 'DOC-199':
                $types = $doc === 'DOC-198' ? ['PROVIDER_PAYABLE'] : ['TAX_CLEARING', 'LEVY_CLEARING'];
                $filters = $doc === 'DOC-198' ? ['provider_id' => $subjectId] + $f : $f;
                $rows = $this->query->entries($tenantId, $filters)->whereIn('e.entry_type', $types)->groupBy('e.entry_type')->selectRaw('e.entry_type, SUM(e.debit_amount) d, SUM(e.credit_amount) c')->get();
                $fig = $rows->mapWithKeys(fn ($r) => [$r->entry_type => (int) $r->c - (int) $r->d])->all();

                return [$doc === 'DOC-198' ? 'Provider settlement statement' : 'Tax and levy breakdown', $fig, [['heading' => 'Balances', 'paragraphs' => $kv($fig)]]];
            default: // DOC-200
                $rows = $this->reports->run($tenantId, 'SETTLEMENT_RECONCILIATION', $f)['rows'];
                $fig = ['settlements' => count($rows), 'difference_minor' => array_sum(array_map(fn ($r) => abs((int) $r['difference_minor']), $rows)),
                    'unapplied_cash_minor' => array_sum(array_column($this->reports->run($tenantId, 'UNAPPLIED_CASH', $f)['rows'], 'unapplied_minor'))];

                return ['Reconciliation statement', $fig, [['heading' => 'Summary', 'paragraphs' => $kv($fig)]]];
        }
    }
}
