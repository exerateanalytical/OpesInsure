<?php

declare(strict_types=1);

namespace App\Application\Compliance\Aml\Screening;

use App\Application\Audit\AuditWriter;
use App\Application\Compliance\Aml\Screening\Models\ScreeningListEntry;
use App\Application\Compliance\Aml\Screening\Models\ScreeningListSource;
use App\Application\Compliance\Aml\Screening\Models\ScreeningListVersion;
use App\Application\Events\OutboxWriter;
use App\Interfaces\Http\Errors\ApiProblemException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Agent E8 — REQ-AML-001 list sources and versioned list imports (CSV / JSON) with maker-checker approval.
 * CSV header: entry_ref,name[,aliases][,date_of_birth][,country][,entry_type] — aliases separated by "|" or ";".
 * JSON: a list of {entry_ref, name, aliases?, date_of_birth?, country?, entry_type?, attributes?}.
 * A version stays PENDING_APPROVAL until a different user approves it; it then becomes the source's ACTIVE version.
 */
final class ScreeningListService
{
    public const LIST_TYPES = ['PEP', 'SANCTIONS', 'WATCHLIST'];

    public function __construct(private readonly NameMatcher $matcher, private readonly AuditWriter $audit, private readonly OutboxWriter $outbox) {}

    /** @param array{code: string, name: string, list_type: string, publisher?: ?string} $d */
    public function createSource(string $tenantId, array $d, ?User $actor): ScreeningListSource
    {
        if (ScreeningListSource::where('tenant_id', $tenantId)->where('code', $d['code'])->exists()) {
            throw new ApiProblemException('SCREENING_SOURCE_EXISTS', 409, 'A screening list source with this code already exists.');
        }
        $s = ScreeningListSource::create(['tenant_id' => $tenantId, 'code' => $d['code'], 'name' => $d['name'], 'list_type' => strtoupper($d['list_type']),
            'publisher' => $d['publisher'] ?? null, 'status' => 'ACTIVE', 'created_by' => $actor?->id]);
        $this->audit->record('aml.screening.source_created', 'screening_list_source', $s->id, ['code' => $s->code, 'list_type' => $s->list_type]);

        return $s;
    }

    public function import(ScreeningListSource $source, string $format, string|array $content, ?string $reference, ?User $actor): ScreeningListVersion
    {
        $format = strtoupper($format);
        $rows = $format === 'CSV' ? $this->parseCsv(is_string($content) ? $content : '') : (is_string($content) ? json_decode($content, true) : $content);
        if (! is_array($rows) || $rows === []) {
            throw new ApiProblemException('SCREENING_IMPORT_EMPTY', 422, 'The import contains no entries.', ['content' => ['No entries.']]);
        }
        if (count($rows) > (int) config('aml.screening.max_import_entries', 200000)) {
            throw new ApiProblemException('SCREENING_IMPORT_TOO_LARGE', 422, 'The import exceeds the maximum number of entries.');
        }
        $entries = [];
        $errors = [];
        foreach (array_values($rows) as $i => $row) {
            $ref = trim((string) ($row['entry_ref'] ?? ''));
            $name = trim((string) ($row['name'] ?? ''));
            if ($ref === '' || $name === '') {
                $errors["entries.$i"] = ['entry_ref and name are required.'];

                continue;
            }
            if (isset($entries[$ref])) {
                $errors["entries.$i"] = ["Duplicate entry_ref {$ref}."];

                continue;
            }
            $aliases = $row['aliases'] ?? [];
            $aliases = is_string($aliases) ? preg_split('/[|;]/', $aliases) : (array) $aliases;
            $aliases = array_values(array_filter(array_map(fn ($a) => trim((string) $a), $aliases), fn ($a) => $a !== ''));
            $dob = trim((string) ($row['date_of_birth'] ?? '')) ?: null;
            if ($dob !== null && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) {
                $errors["entries.$i"] = ['date_of_birth must be YYYY-MM-DD.'];

                continue;
            }
            $type = strtoupper(trim((string) ($row['entry_type'] ?? ''))) ?: $source->list_type;
            if (! in_array($type, self::LIST_TYPES, true)) {
                $errors["entries.$i"] = ['entry_type must be PEP, SANCTIONS or WATCHLIST.'];

                continue;
            }
            $country = strtoupper(trim((string) ($row['country'] ?? ''))) ?: null;
            $norm = array_values(array_unique(array_filter(array_map(fn ($n) => $this->matcher->normalize($n), [$name, ...$aliases]))));
            $data = ['entry_ref' => $ref, 'entry_type' => $type, 'name' => $name, 'aliases' => $aliases, 'date_of_birth' => $dob,
                'country' => $country !== null ? substr($country, 0, 2) : null, 'attributes' => (array) ($row['attributes'] ?? [])];
            $entries[$ref] = $data + ['normalized_names' => $norm, 'entry_hash' => hash('sha256', json_encode($data))];
        }
        if ($errors !== []) {
            throw new ApiProblemException('SCREENING_IMPORT_INVALID', 422, 'Some entries are invalid; nothing was imported.', array_slice($errors, 0, 50, true));
        }

        return DB::transaction(function () use ($source, $format, $entries, $reference, $actor, $content) {
            ScreeningListSource::whereKey($source->id)->lockForUpdate()->first();
            $version = (int) ScreeningListVersion::where('source_id', $source->id)->max('version') + 1;
            $v = ScreeningListVersion::create(['tenant_id' => $source->tenant_id, 'source_id' => $source->id, 'version' => $version, 'status' => 'PENDING_APPROVAL',
                'format' => $format, 'source_reference' => $reference, 'content_sha256' => hash('sha256', is_string($content) ? $content : json_encode($content)),
                'entry_count' => count($entries), 'imported_by' => $actor?->id]);
            foreach (array_chunk(array_values($entries), 500) as $chunk) {
                ScreeningListEntry::insert(array_map(fn ($e) => ['id' => (string) Str::uuid(), 'tenant_id' => $source->tenant_id, 'version_id' => $v->id,
                    'entry_ref' => $e['entry_ref'], 'entry_type' => $e['entry_type'], 'name' => $e['name'], 'aliases' => json_encode($e['aliases']),
                    'normalized_names' => json_encode($e['normalized_names']), 'date_of_birth' => $e['date_of_birth'], 'country' => $e['country'],
                    'attributes' => json_encode((object) $e['attributes']), 'entry_hash' => $e['entry_hash'], 'created_at' => now()], $chunk));
            }
            $this->audit->record('aml.screening.list_version_imported', 'screening_list_version', $v->id, ['source_id' => $source->id, 'version' => $version, 'entry_count' => $v->entry_count, 'format' => $format]);
            $this->outbox->record('aml.screening.list_version_imported', 'screening_list_version', $v->id, ['tenant_id' => $source->tenant_id, 'source_id' => $source->id, 'version' => $version, 'entry_count' => $v->entry_count]);

            return $v;
        });
    }

    /** Checker decision: APPROVE activates the version (previous ACTIVE → SUPERSEDED); REJECT closes it. */
    public function decide(ScreeningListVersion $v, bool $approve, ?string $note, User $checker): ScreeningListVersion
    {
        if ($v->status !== 'PENDING_APPROVAL') {
            throw new ApiProblemException('SCREENING_VERSION_NOT_PENDING', 409, 'Only a version pending approval can be decided.');
        }
        if ($v->imported_by !== null && $v->imported_by === $checker->id) {
            throw new ApiProblemException('MAKER_CHECKER_VIOLATION', 403, 'The user who imported a list version cannot approve it.');
        }

        $v = DB::transaction(function () use ($v, $approve, $note, $checker) {
            if ($approve) {
                ScreeningListVersion::where('source_id', $v->source_id)->where('status', 'ACTIVE')->update(['status' => 'SUPERSEDED', 'updated_at' => now()]);
                $v->update(['status' => 'ACTIVE', 'decided_by' => $checker->id, 'decided_at' => now(), 'decision_note' => $note, 'activated_at' => now()]);
            } else {
                $v->update(['status' => 'REJECTED', 'decided_by' => $checker->id, 'decided_at' => now(), 'decision_note' => $note]);
            }
            $event = $approve ? 'aml.screening.list_version_activated' : 'aml.screening.list_version_rejected';
            $this->audit->record($event, 'screening_list_version', $v->id, ['source_id' => $v->source_id, 'version' => $v->version, 'note' => $note]);
            $this->outbox->record($event, 'screening_list_version', $v->id, ['tenant_id' => $v->tenant_id, 'source_id' => $v->source_id, 'version' => $v->version]);

            return $v->fresh();
        });
        if ($approve && config('aml.screening.rescreen_on_activation', true)) {
            app(ScreeningService::class)->rescreenTenant($v->tenant_id, 'LIST_UPDATE', $checker);
        }

        return $v;
    }

    /** @return list<array<string, string>> */
    private function parseCsv(string $csv): array
    {
        $lines = preg_split('/\r\n|\n|\r/', trim($csv)) ?: [];
        if (count($lines) < 2) {
            return [];
        }
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), str_getcsv(array_shift($lines)));
        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = str_getcsv($line);
            $rows[] = array_combine($header, array_pad(array_slice($cells, 0, count($header)), count($header), ''));
        }

        return $rows;
    }
}
