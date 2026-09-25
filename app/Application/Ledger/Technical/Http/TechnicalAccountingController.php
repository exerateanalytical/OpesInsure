<?php

declare(strict_types=1);

namespace App\Application\Ledger\Technical\Http;

use App\Application\Ledger\Technical\ActuarialImportService;
use App\Application\Ledger\Technical\TechnicalAccountingService;
use App\Application\Ledger\Technical\UprPostingService;
use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Batch 10-9: REQ-ACC-004 technical accounting reports (JSON + CSV), actuarial imports, UPR period-end posting. */
final class TechnicalAccountingController
{
    private const REPORTS = ['premiums', 'claims', 'summary'];

    public function __construct(private TenantContext $tenant, private TechnicalAccountingService $tech, private ActuarialImportService $imports) {}

    public function report(Request $r, string $report): JsonResponse|StreamedResponse
    {
        abort_unless(in_array($report, self::REPORTS, true), 404);
        $d = $r->validate([
            'from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from',
            'carrier_id' => 'nullable|uuid', 'line_code' => 'nullable|string|max:64', 'format' => 'nullable|in:json,csv',
        ]);
        $from = CarbonImmutable::parse($d['from']);
        $to = CarbonImmutable::parse($d['to']);
        $rows = $this->tech->{$report}($this->tenant->id(), $from, $to, $d['carrier_id'] ?? null, $d['line_code'] ?? null);

        if (($d['format'] ?? 'json') === 'csv') {
            return $this->csv($rows, "technical-{$report}-{$d['from']}-{$d['to']}.csv");
        }

        return response()->json(['data' => $rows, 'meta' => ['report' => $report, 'from' => $d['from'], 'to' => $d['to'], 'basis' => 'pro_rata_daily']]);
    }

    public function listImports(Request $r): JsonResponse
    {
        $d = $r->validate(['kind' => 'nullable|in:'.implode(',', ActuarialImportService::KINDS), 'status' => 'nullable|string|max:16']);

        return response()->json(['data' => DB::table('technical_actuarial_imports')->where('tenant_id', $this->tenant->id())
            ->when($d['kind'] ?? null, fn ($q, $v) => $q->where('kind', $v))->when($d['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('period_end')->orderByDesc('version')->limit(200)->get()]);
    }

    public function showImport(string $import): JsonResponse
    {
        $i = $this->imports->find($this->tenant->id(), $import);

        return response()->json(['data' => (array) $i + ['values' => DB::table('technical_actuarial_values')->where('import_id', $i->id)->orderBy('metric')->get()]]);
    }

    public function storeImport(Request $r): JsonResponse
    {
        $d = $r->validate([
            'kind' => 'required|in:'.implode(',', ActuarialImportService::KINDS), 'period_end' => 'required|date_format:Y-m-d',
            'source' => 'required|string|max:120', 'notes' => 'nullable|string|max:2000',
            'values' => 'required|array|min:1|max:5000', 'values.*.carrier_id' => 'nullable|uuid', 'values.*.line_code' => 'nullable|string|max:64',
            'values.*.metric' => 'required|string|max:48|regex:/^[A-Za-z0-9_]+$/', 'values.*.amount_minor' => 'required|integer',
            'values.*.currency' => 'required|string|size:3',
        ]);

        return response()->json(['data' => $this->imports->import($this->tenant->id(), $d['kind'], $d['period_end'], $d['source'], $d['values'], $r->user()->id, $d['notes'] ?? null)], 201);
    }

    public function approveImport(Request $r, string $import): JsonResponse
    {
        return response()->json(['data' => $this->imports->approve($this->tenant->id(), $import, $r->user()->id)]);
    }

    public function rejectImport(Request $r, string $import): JsonResponse
    {
        $d = $r->validate(['reason' => 'required|string|max:1000']);

        return response()->json(['data' => $this->imports->reject($this->tenant->id(), $import, $r->user()->id, $d['reason'])]);
    }

    public function postUpr(Request $r, UprPostingService $upr): JsonResponse
    {
        $d = $r->validate(['period_end' => 'required|date_format:Y-m-d|before_or_equal:today']);

        return response()->json(['data' => $upr->post($this->tenant->id(), CarbonImmutable::parse($d['period_end']), $r->user()->id, $r->header('X-Correlation-Id') ?: (string) Str::uuid())], 201);
    }

    /** @param list<array<string,mixed>> $rows */
    private function csv(array $rows, string $name): StreamedResponse
    {
        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'wb');
            $head = [];
            foreach ($rows as $row) {
                $head = array_values(array_unique([...$head, ...array_keys($this->flat($row))]));
            }
            fputcsv($out, $head);
            foreach ($rows as $row) {
                $f = $this->flat($row);
                fputcsv($out, array_map(fn ($k) => $this->safe($f[$k] ?? ''), $head));
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function flat(array $row): array
    {
        $out = [];
        foreach ($row as $k => $v) {
            if (is_array($v)) {
                foreach ($v as $sk => $sv) {
                    $out[$k.'_'.$sk] = $sv;
                }
            } else {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    /** CSV formula-injection guard. */
    private function safe(mixed $v): string
    {
        $s = (string) ($v ?? '');

        return $s !== '' && in_array($s[0], ['=', '+', '@', "\t", "\r"], true) ? "'".$s : $s;
    }
}
