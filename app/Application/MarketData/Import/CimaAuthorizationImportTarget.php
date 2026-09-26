<?php

declare(strict_types=1);

namespace App\Application\MarketData\Import;

use App\Application\Import\ImportTarget;
use App\Application\Regulatory\CimaAuthorizationService;
use App\Models\Regulatory\InsurerRegulatoryAuthorization;
use App\Models\Regulatory\RegulatoryBranch;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Gap closure 01 — insurer CIMA branch authorizations (PENDING_OFFICIAL_IMPORT). Loads regulator decisions through the
 * generic ImportPipeline into the canonical insurer_regulatory_authorizations via CimaAuthorizationService::record, so
 * every row still needs evidence (source document) and a second approver before it authorizes anything. Until then
 * the carrier stays blocked for new product publication (CimaPublicationGuard).
 */
final class CimaAuthorizationImportTarget implements ImportTarget
{
    use ResolvesMarketParties;

    public const KEY = 'cima_insurer_authorizations';

    /** Pack statuses accepted as a NEW decision; SUSPENDED/REVOKED/EXPIRED are status changes on a recorded agrément. */
    public const IMPORTABLE_STATUSES = ['AUTHORIZED', 'PENDING_VERIFICATION'];

    public function __construct(private readonly CimaAuthorizationService $service) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Insurer CIMA branch authorizations';
    }

    public function fields(): array
    {
        return ['insurer' => true, 'cima_branch_codes' => true, 'decision_reference' => true, 'authority' => true, 'source_document' => true,
            'source_url' => false, 'effective_from' => true, 'effective_until' => false, 'status' => false];
    }

    public function params(array $params): array
    {
        return [];
    }

    public function check(array $row, array $params, array &$seen): array
    {
        try {
            $carrier = $this->carrier($row);
            $status = strtoupper(trim((string) ($row['status'] ?? '') ?: 'AUTHORIZED'));
            if (! in_array($status, self::IMPORTABLE_STATUSES, true)) {
                throw ValidationException::withMessages(['status' => ["Status {$status} is a change on a recorded agrément, not an import"]]);
            }
            $codes = $this->branchCodes($row);
            $ref = trim((string) ($row['decision_reference'] ?? ''));
            if ($ref === '') {
                throw ValidationException::withMessages(['decision_reference' => ['decision_reference is required']]);
            }
            $key = $carrier->id.'/'.strtoupper($ref);
            if (isset($seen[$key])) {
                return ['status' => 'ERROR', 'error' => "{$ref} repeated in the file"];
            }
            $seen[$key] = true;
            if ($dupe = InsurerRegulatoryAuthorization::where('carrier_id', $carrier->id)->whereRaw('upper(authorization_reference) = ?', [strtoupper($ref)])->first()) {
                return ['status' => 'DUPLICATE', 'key' => $key, 'matches' => $dupe->id];
            }
            foreach (['source_document' => 'Regulator evidence (source document) is required', 'effective_from' => 'effective_from is required'] as $f => $msg) {
                if (blank($row[$f] ?? null)) {
                    throw ValidationException::withMessages([$f => [$msg]]);
                }
            }

            return ['status' => 'NEW', 'key' => $key.' ['.implode(',', $codes).']'];
        } catch (\Throwable $e) {
            return ['status' => 'ERROR', 'error' => $this->error($e)];
        }
    }

    public function import(array $row, array $params, ?User $actor, string $batchId): string
    {
        $auth = $this->service->record($this->carrier($row), [
            'authorization_reference' => trim((string) $row['decision_reference']), 'source' => 'CIMA_CRCA_DECISION',
            'source_authority' => $row['authority'] ?? null, 'source_document' => $row['source_document'],
            'effective_from' => $row['effective_from'], 'effective_until' => ($row['effective_until'] ?? null) ?: null,
            'notes' => 'Imported (gap closure 01, batch '.$batchId.')',
        ], $this->branchCodes($row), $actor);
        $auth->update(['source_url' => ($row['source_url'] ?? null) ?: null, 'import_batch_id' => $batchId]);

        return $auth->id;
    }

    public function finish(array $params): void {}

    /** Accepts canonical codes or branch numbers (3 / 03). @return list<string> */
    private function branchCodes(array $row): array
    {
        $out = [];
        foreach ($this->list($row['cima_branch_codes'] ?? '') as $c) {
            $b = RegulatoryBranch::current()->where('regime', 'CIMA')
                ->where(fn ($q) => $q->where('code', strtoupper($c))->when(ctype_digit($c), fn ($q) => $q->orWhere('number', (int) $c)))->first();
            $out[] = $b?->code ?? throw ValidationException::withMessages(['cima_branch_codes' => ["Unknown CIMA branch {$c}"]]);
        }
        if ($out === []) {
            throw ValidationException::withMessages(['cima_branch_codes' => ['At least one CIMA branch is required']]);
        }

        return array_values(array_unique($out));
    }
}
