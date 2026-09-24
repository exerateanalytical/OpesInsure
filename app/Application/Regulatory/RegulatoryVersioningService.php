<?php

declare(strict_types=1);

namespace App\Application\Regulatory;

use App\Application\Audit\AuditWriter;
use App\Models\Regulatory\RegulatoryTerm;
use App\Models\Regulatory\RegulatoryTermTranslation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Regulatory rows are never edited in place: a change is a new effective-dated
 * row with its own regulatory_version, and the previous row is closed the day
 * before (effective_until). Seeded rows stay intact and undeletable.
 */
final class RegulatoryVersioningService
{
    public function __construct(private readonly AuditWriter $audit, private readonly RegulatoryTerminologyService $terms) {}

    /**
     * @param  array<string, mixed>  $changes  new values for the regulatory columns
     * @param  array<string, string>  $labels  terms only: locale => preferred label
     */
    public function supersede(Model $row, array $changes, string $version, string $effectiveFrom, string $sourceReference, User $actor, array $labels = []): Model
    {
        if (blank($version) || blank($sourceReference)) {
            throw ValidationException::withMessages(['regulatory_version' => ['A new regulatory version and a source reference are required.']]);
        }
        if ($version === $row->regulatory_version) {
            throw ValidationException::withMessages(['regulatory_version' => ['The new version must differ from the current one.']]);
        }
        $from = CarbonImmutable::parse($effectiveFrom);
        if ($row->effective_from && $from->lte($row->effective_from)) {
            throw ValidationException::withMessages(['effective_from' => ['The new version must start after the current version.']]);
        }

        return DB::transaction(function () use ($row, $changes, $version, $from, $sourceReference, $actor, $labels) {
            $attrs = collect($row->getAttributes())->except(['id', 'created_at', 'updated_at', 'effective_until'])->all();
            $new = $row->newInstance(array_merge($attrs, $changes, [
                'regulatory_version' => $version, 'effective_from' => $from->toDateString(), 'source_reference' => $sourceReference,
                'status' => 'ACTIVE', 'is_seeded' => false,
            ]));
            $new->save();
            $row->update(['effective_until' => $from->subDay()->toDateString()]);

            if ($row instanceof RegulatoryTerm) {
                foreach ($row->translations as $t) {
                    RegulatoryTermTranslation::create(['regulatory_term_id' => $new->id, 'locale' => $t->locale, 'label' => ($t->context === 'PREFERRED' ? ($labels[$t->locale] ?? null) : null) ?: $t->label, 'context' => $t->context]);
                }
            }
            $this->audit->record('regulatory.version.superseded', $row->getTable(), $row->getKey(), ['new_id' => $new->getKey(), 'version' => $version, 'by' => $actor->id]);
            $this->terms->flush();

            return $new;
        });
    }
}
