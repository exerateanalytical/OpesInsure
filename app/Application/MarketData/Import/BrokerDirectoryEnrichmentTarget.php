<?php

declare(strict_types=1);

namespace App\Application\MarketData\Import;

use App\Application\Audit\AuditWriter;
use App\Application\Import\ImportTarget;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Gap closure 01 — broker directory enrichment (population VERIFIED_PUBLIC_SOURCE: 123 DGTCFM brokers, already seeded as
 * official-register partners). Contacts, locality, responsible person, licence reference and branches are loaded into the
 * canonical institution directory (institution_profiles / institution_offices, now carrier XOR partner) — the same tables
 * as the 29-insurer directory. DGTCFM stays the authorization source of truth: a row must match an existing register
 * broker, never creates one, and never overwrites an existing (possibly admin-edited) profile.
 */
final class BrokerDirectoryEnrichmentTarget implements ImportTarget
{
    use ResolvesMarketParties;

    public const KEY = 'broker_directory_enrichment';

    public const DATASET = 'CM_BROKER_DIRECTORY';

    public const VERIFICATION_STATUSES = ['VERIFIED', 'PARTIALLY_VERIFIED', 'PENDING_VERIFICATION'];

    public function __construct(private readonly AuditWriter $audit) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Broker directory enrichment (DGTCFM brokers)';
    }

    public function fields(): array
    {
        return ['official_sequence' => false, 'legal_name' => true, 'trade_name' => false, 'locality' => false, 'postal_box' => false,
            'phones' => false, 'emails' => false, 'website' => false, 'responsible_person' => false, 'license_reference' => false,
            'authorized_year' => false, 'street_address' => false, 'branches' => false, 'source_url' => true, 'verification_status' => false];
    }

    public function params(array $params): array
    {
        return [];
    }

    public function check(array $row, array $params, array &$seen): array
    {
        try {
            $partner = $this->broker($row);
            if (isset($seen[$partner->id])) {
                return ['status' => 'ERROR', 'error' => "{$partner->legal_name} repeated in the file"];
            }
            $seen[$partner->id] = true;
            if (DB::table('institution_profiles')->where('partner_id', $partner->id)->exists()) {
                return ['status' => 'DUPLICATE', 'key' => $partner->canonical_id ?? $partner->id, 'matches' => $partner->legal_name];
            }
            if (blank($row['source_url'] ?? null)) {
                return ['status' => 'ERROR', 'error' => 'source_url is required (provenance)'];
            }
            $year = $row['authorized_year'] ?? null;
            if (filled($year) && (! ctype_digit((string) $year) || (int) $year < 1950 || (int) $year > (int) date('Y'))) {
                return ['status' => 'ERROR', 'error' => "Invalid authorized_year {$year}"];
            }
            $status = strtoupper((string) ($row['verification_status'] ?? '') ?: 'PENDING_VERIFICATION');
            if (! in_array($status, self::VERIFICATION_STATUSES, true)) {
                return ['status' => 'ERROR', 'error' => "Unknown verification_status {$status}"];
            }

            return ['status' => 'NEW', 'key' => $partner->canonical_id ?? $partner->id];
        } catch (\Throwable $e) {
            return ['status' => 'ERROR', 'error' => $this->error($e)];
        }
    }

    public function import(array $row, array $params, ?User $actor, string $batchId): string
    {
        $partner = $this->broker($row);
        $id = (string) Str::uuid();
        $blank = fn (string $f) => ($v = trim((string) ($row[$f] ?? ''))) === '' ? null : $v;
        DB::transaction(function () use ($row, $partner, $id, $batchId, $blank): void {
            DB::table('institution_profiles')->insert([
                'id' => $id, 'partner_id' => $partner->id, 'carrier_id' => null,
                'directory_id' => (string) ($partner->canonical_id ?? 'BRK-'.$partner->regulator_sequence),
                'directory_name' => $blank('trade_name') ?? $partner->legal_name,
                'official_sequence' => $partner->regulator_sequence, 'locality' => $blank('locality'), 'po_box' => $blank('postal_box'),
                'phones' => json_encode($this->list($row['phones'] ?? [])), 'emails' => json_encode($this->list($row['emails'] ?? [])),
                'website' => $blank('website'), 'responsible_person' => $blank('responsible_person'), 'license_reference' => $blank('license_reference'),
                'authorized_year' => $blank('authorized_year'), 'street_address' => $blank('street_address'), 'source_url' => $blank('source_url'),
                'sources' => json_encode(array_values(array_filter([$blank('source_url'), 'https://dgtcfm.cm/les-acteurs-de-la-profession/liste-des-courtiers/']))),
                'verification_status' => strtoupper((string) ($row['verification_status'] ?? '') ?: 'PENDING_VERIFICATION'), 'verified_at' => null,
                'dataset' => self::DATASET, 'dataset_version' => 'gap-closure-01', 'import_batch_id' => $batchId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            // branches: "Name@City@Address@Phone" entries separated by ; — office names are unique per broker.
            foreach ($this->list($row['branches'] ?? []) as $i => $entry) {
                [$name, $city, $address, $phone] = array_pad(array_map('trim', explode('@', $entry)), 4, null);
                DB::table('institution_offices')->insertOrIgnore([
                    'id' => (string) Str::uuid(), 'partner_id' => $partner->id, 'carrier_id' => null, 'office_type' => $i === 0 ? 'HEAD_OFFICE' : 'BRANCH',
                    'name' => mb_substr((string) $name, 0, 255), 'city' => $city ?: null, 'address' => $address ?: null, 'phone' => $phone ?: null,
                    'sort_order' => $i, 'source' => self::DATASET, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
        $this->audit->record('market.broker_directory.enriched', 'partner', $partner->id, ['profile_id' => $id, 'batch_id' => $batchId]);

        return $id;
    }

    public function finish(array $params): void {}
}
