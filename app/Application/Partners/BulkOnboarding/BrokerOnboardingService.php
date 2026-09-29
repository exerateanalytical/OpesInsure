<?php

declare(strict_types=1);

namespace App\Application\Partners\BulkOnboarding;

use App\Application\Identity\Rbac\PlatformAuthority;
use App\Application\Import\ImportPipeline;
use App\Domain\Tenancy\TenantContext;
use App\Models\Import\ImportBatch;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Throwable;

/**
 * S9 — the platform-admin face of bulk broker onboarding (Filament /admin screen and /api/v1/platform/broker-onboarding).
 * Everything that changes data goes through ImportPipeline with the broker_onboarding target; this class only adds the
 * access rule (platform tenant + tenant.manage + identity.invite), the template and the per-row preview / result report.
 */
final class BrokerOnboardingService
{
    public const PERMISSIONS = ['tenant.manage', 'identity.invite'];

    public function __construct(private readonly ImportPipeline $pipeline, private readonly PlatformAuthority $platform, private readonly TenantContext $tenant) {}

    public function allows(?User $user): bool
    {
        $tenantId = rescue(fn () => $this->tenant->id(), null, false);

        return $user !== null && $tenantId !== null && $this->platform->isPlatformTenant($tenantId)
            && collect(self::PERMISSIONS)->every(fn ($p) => $user->hasPermission($p));
    }

    public function upload(string $path, string $filename, User $maker): ImportBatch
    {
        abort_unless($this->allows($maker), 403, __('security.platform_only'));

        return $this->pipeline->upload(BrokerOnboardingTarget::KEY, [], $path, $filename, $maker, [], $this->tenant->id());
    }

    /** Batches of this screen: platform tenant only. */
    public function query()
    {
        return ImportBatch::query()->where('target', BrokerOnboardingTarget::KEY)->where('tenant_id', $this->tenant->id());
    }

    /** Template: header row + one example row. */
    public function templateRows(): array
    {
        return [array_keys(BrokerOnboardingTarget::FIELDS), [
            'EXEMPLE COURTAGE SARL', 'Exemple Courtage', 'RC/DLA/2019/B/1234', 'M012345678901A', 'MINFI/DGTCFM/0123', now()->addYear()->toDateString(),
            'Douala', 'Rue Joss, Bonanjo', '+237233421234', 'contact@exemple-courtage.cm',
            'Jean Mbarga', '+237690000001', 'j.mbarga@exemple-courtage.cm', 'Agence Akwa@Douala|Agence Bastos@Yaoundé', 'fr',
        ]];
    }

    public function templateCsv(): string
    {
        return $this->csv($this->templateRows());
    }

    public function templateXlsx(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'brk').'.xlsx';
        $writer = new XlsxWriter;
        $writer->openToFile($path);
        foreach ($this->templateRows() as $r) {
            $writer->addRow(Row::fromValues($r));
        }
        $writer->close();

        return $path;
    }

    /**
     * One line per file row: status (NEW / DUPLICATE / ERROR before import; CREATED / SKIPPED / FAILED after) and the reason.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(ImportBatch $batch, bool $withCodes = false): array
    {
        $report = $batch->report ?? [];
        $errors = collect($report['errors'] ?? [])->keyBy('row');
        $dupes = collect($report['duplicates'] ?? [])->keyBy('row');
        $result = $batch->result ?? [];
        $created = collect($result['created'] ?? [])->keyBy('row');
        $skipped = collect($result['skipped'] ?? [])->keyBy('row');
        $failed = collect($result['failed'] ?? [])->keyBy('row');
        $out = [];
        foreach ($batch->rows ?? [] as $i => $row) {
            $line = $i + 1;
            [$status, $reason] = match (true) {
                $created->has($line) => ['CREATED', ($created[$line]['linked_register_partner'] ?? false) ? __('bulk_onboarding.status.linked_register') : null],
                $failed->has($line) => ['FAILED', $failed[$line]['reason'] ?? null],
                $skipped->has($line) => [$skipped[$line]['status'] === 'DUPLICATE' ? 'SKIPPED_DUPLICATE' : 'SKIPPED_ERROR', $skipped[$line]['reason'] ?? null],
                $errors->has($line) => ['ERROR', $errors[$line]['error']],
                $dupes->has($line) => ['DUPLICATE', $dupes[$line]['matches'] ?? null],
                default => ['NEW', null],
            };
            $c = $created[$line] ?? [];
            $code = null;
            if ($withCodes && ($c['token_encrypted'] ?? null)) {
                $code = rescue(fn () => Crypt::decryptString($c['token_encrypted']), null, false);
            }
            $out[] = [
                'row' => $line, 'legal_name' => $row['legal_name'] ?? null, 'niu' => $row['niu'] ?? null, 'licence_number' => $row['licence_number'] ?? null,
                'admin' => trim(($row['admin_name'] ?? '').' '.($row['admin_phone'] ?? $row['admin_email'] ?? '')),
                'status' => $status, 'reason' => $reason, 'tenant_id' => $c['id'] ?? null, 'partner_id' => $c['partner_id'] ?? null,
                'invitation_id' => $c['invitation_id'] ?? null,
                'delivery' => collect($c['delivery'] ?? [])->map(fn ($s, $ch) => "$ch:$s")->implode(' '),
                'invitation_code' => $code,
            ];
        }

        return $out;
    }

    /** Downloadable per-row report (preview before approval, result after). Undelivered invitation codes are included. */
    public function reportCsv(ImportBatch $batch): string
    {
        $rows = $this->rows($batch, true);
        $head = ['row', 'legal_name', 'niu', 'licence_number', 'admin', 'status', 'reason', 'tenant_id', 'partner_id', 'invitation_id', 'delivery', 'invitation_code'];

        return $this->csv([$head, ...array_map(fn ($r) => array_map(fn ($k) => $r[$k], $head), $rows)]);
    }

    private function csv(array $rows): string
    {
        $h = fopen('php://temp', 'r+');
        fwrite($h, "\xEF\xBB\xBF");
        foreach ($rows as $r) {
            fputcsv($h, array_map(fn ($v) => $v === null ? '' : (string) $v, $r), ',', '"', '');
        }
        rewind($h);
        try {
            return (string) stream_get_contents($h);
        } catch (Throwable) {
            return '';
        } finally {
            fclose($h);
        }
    }
}
