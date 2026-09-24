<?php

declare(strict_types=1);

namespace App\Application\Temporal;

use App\Domain\Shared\Clock\Clock;
use App\Domain\Shared\Clock\SystemClock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * REQ-TMP-001 / REQ-TMP-002 — ICE E1 §1.4 VersionResolver.
 *
 * Valid time:   DAY granularity  = closed-closed [from, until] on the business
 *                                  date of the reference instant in its timezone.
 *               INSTANT          = half-open [from, to).
 * Knowledge time (as-of): bitemporal artifacts filter recorded_at <= asOf <
 *               superseded_at; without asOf only current (non-superseded) rows.
 *               Uni-temporal artifacts fall back to created_at <= asOf (§0.3).
 * Returns exactly one version or throws (INV-1.3) — never "latest".
 */
final class VersionResolver
{
    /** @var array<string, object> */
    private array $registry = [];

    private readonly Clock $clock;

    // Clock falls back to SystemClock until TemporalServiceProvider is registered.
    public function __construct(private readonly ReferenceDateResolver $referenceDates, ?Clock $clock = null)
    {
        $this->clock = $clock ?? new SystemClock((string) config('app.timezone', 'Africa/Douala'));
    }

    /** @param array<string, mixed> $key */
    public function resolve(string $artifactType, array $key, ReferenceInstant $at, ?CarbonImmutable $recordedAsOf = null): ResolvedVersion
    {
        $def = $this->definition($artifactType);
        $keyColumns = json_decode($def->key_columns, true);
        ksort($key);
        $expected = $keyColumns;
        sort($expected);
        if (array_keys($key) !== $expected) {
            throw new InvalidArgumentException("Key for {$artifactType} must be exactly: ".implode(',', $keyColumns));
        }

        $q = DB::table($def->source_table);
        foreach ($key as $column => $value) {
            $q->where($this->ident($column), $value);
        }
        $statuses = json_decode($def->effective_statuses, true) ?: [];
        if ($def->status_column && $statuses !== []) {
            $q->whereIn($this->ident($def->status_column), $statuses);
        }
        $this->applyValidTime($q, $def, $at);
        $this->applyKnowledgeTime($q, $def, $recordedAsOf);

        $select = ['id', $this->ident($def->from_column).' as __from'];
        if ($def->until_column) {
            $select[] = $this->ident($def->until_column).' as __until';
        }
        if ($def->version_column) {
            $select[] = $this->ident($def->version_column).' as __version';
        }
        $rows = $q->limit(2)->get($select);

        $atIso = $at->referenceAt->toIso8601String();
        if ($rows->isEmpty()) {
            throw new TemporalResolutionException(TemporalResolutionException::NO_VERSION, $artifactType, $key, $atIso);
        }
        if ($rows->count() > 1) {
            throw new TemporalResolutionException(TemporalResolutionException::AMBIGUOUS, $artifactType, $key, $atIso);
        }
        $r = $rows->first();

        return new ResolvedVersion($artifactType, $key, (string) $r->id, $r->__version ?? null,
            $r->__from !== null ? (string) $r->__from : null, isset($r->__until) ? (string) $r->__until : null, $def->source_table);
    }

    /**
     * Resolve every artifact for one business operation, each against the
     * reference instant its reference_date_rule prescribes, and persist the
     * append-only transaction_resolved_versions row (INV-1.4).
     *
     * @param  array<string, array<string, mixed>>  $artifactKeys  artifact_type => key
     */
    public function resolveSet(string $operation, Model $subject, array $artifactKeys, ?CarbonImmutable $recordedAsOf = null, ?string $timezone = null): ResolvedVersionSet
    {
        // Knowledge-time filtering applies only to an explicit replay; a live
        // resolution uses current knowledge and records "now" as its as-of.
        $replayAsOf = $recordedAsOf;
        $recordedAsOf ??= $this->clock->now();
        $versions = [];
        $primaryAt = null;
        foreach ($artifactKeys as $type => $key) {
            $category = $this->definition($type)->rule_category;
            $at = $this->referenceDates->for($operation, $subject, $type, $category, $timezone);
            $primaryAt ??= $at->referenceAt;
            $versions[] = $this->resolve($type, $key, $at, $replayAsOf);
        }
        $primaryAt ??= $this->clock->now();
        $payload = array_map(fn (ResolvedVersion $v) => $v->toArray(), $versions);
        $hash = hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $id = (string) Str::uuid();
        DB::table('transaction_resolved_versions')->insert([
            'id' => $id, 'tenant_id' => $subject->getAttribute('tenant_id'), 'subject_type' => $subject->getMorphClass(),
            'subject_id' => (string) $subject->getKey(), 'operation' => $operation, 'reference_at' => $primaryAt->toIso8601String(),
            'recorded_as_of' => $recordedAsOf->toIso8601String(), 'versions' => json_encode($payload), 'versions_hash' => $hash, 'created_at' => $this->clock->now()->toIso8601String(),
        ]);

        return new ResolvedVersionSet($id, $operation, $primaryAt, $recordedAsOf, $versions, $hash);
    }

    public function definition(string $artifactType): object
    {
        return $this->registry[$artifactType] ??= DB::table('versioned_artifact_registry')->where('artifact_type', $artifactType)->first()
            ?? throw new TemporalResolutionException(TemporalResolutionException::UNKNOWN_ARTIFACT, $artifactType);
    }

    private function applyValidTime(Builder $q, object $def, ReferenceInstant $at): void
    {
        $from = $this->ident($def->from_column);
        $until = $def->until_column ? $this->ident($def->until_column) : null;
        if ($def->granularity === 'DAY') {
            $date = $at->businessDate();
            $q->whereDate($from, '<=', $date);
            if ($until) {
                $q->where(fn (Builder $w) => $w->whereNull($until)->orWhereDate($until, '>=', $date));
            }

            return;
        }
        $instant = $at->referenceAt->toIso8601String();
        $q->where($from, '<=', $instant);
        if ($until) {
            $q->where(fn (Builder $w) => $w->whereNull($until)->orWhere($until, '>', $instant));
        }
    }

    private function applyKnowledgeTime(Builder $q, object $def, ?CarbonImmutable $asOf): void
    {
        if ($def->bitemporal) {
            if ($asOf === null) {
                $q->whereNull('superseded_at');

                return;
            }
            $q->where('recorded_at', '<=', $asOf->toIso8601String())
                ->where(fn (Builder $w) => $w->whereNull('superseded_at')->orWhere('superseded_at', '>', $asOf->toIso8601String()));

            return;
        }
        if ($asOf !== null) {
            $q->where('created_at', '<=', $asOf->toIso8601String());
        }
    }

    private function ident(string $column): string
    {
        if (! preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $column)) {
            throw new InvalidArgumentException("Illegal column identifier: {$column}");
        }

        return $column;
    }
}
