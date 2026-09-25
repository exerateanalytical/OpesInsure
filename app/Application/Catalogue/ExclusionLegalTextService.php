<?php

declare(strict_types=1);

namespace App\Application\Catalogue;

use App\Application\Audit\AuditWriter;
use App\Application\Temporal\ReferenceInstant;
use App\Application\Temporal\TemporalResolutionException;
use App\Application\Temporal\VersionResolver;
use App\Models\Catalogue\ExclusionLegalText;
use App\Models\ExclusionDefinition;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * REQ-PRD-006 — versioned legal wording for exclusions / extensions (EN/FR),
 * maker-checker approved, effective-dated and resolved by the Temporal engine
 * (artifact `exclusion_legal_text`). Approved texts are frozen by trigger.
 */
final class ExclusionLegalTextService
{
    public function __construct(private readonly AuditWriter $audit, private readonly VersionResolver $versions) {}

    public function draft(ExclusionDefinition $exclusion, array $data, User $maker): ExclusionLegalText
    {
        foreach (['en', 'fr'] as $locale) {
            if (blank($data['text'][$locale] ?? null)) {
                throw ValidationException::withMessages(["text.{$locale}" => 'Legal text is required in English and French.']);
            }
        }

        return DB::transaction(function () use ($exclusion, $data, $maker) {
            ExclusionDefinition::whereKey($exclusion->id)->lockForUpdate()->first();
            $version = (int) ExclusionLegalText::where('exclusion_definition_id', $exclusion->id)->max('version') + 1;
            $text = ExclusionLegalText::create([
                'exclusion_definition_id' => $exclusion->id, 'version' => $version, 'text' => ['en' => $data['text']['en'], 'fr' => $data['text']['fr']],
                'legal_reference' => $data['legal_reference'] ?? null, 'effective_from' => $data['effective_from'], 'effective_until' => $data['effective_until'] ?? null,
                'status' => 'DRAFT', 'text_hash' => hash('sha256', json_encode(['en' => $data['text']['en'], 'fr' => $data['text']['fr']], JSON_UNESCAPED_UNICODE)),
                'created_by' => $maker->id,
            ]);
            $this->audit->record('catalogue.legal_text.drafted', 'exclusion_legal_text', $text->id, ['exclusion' => $exclusion->code, 'version' => $version]);

            return $text;
        });
    }

    /** Checker step. Closes the previous approved text the day before the new one starts. */
    public function approve(ExclusionLegalText $text, User $checker): ExclusionLegalText
    {
        if ($text->status !== 'DRAFT') {
            throw ValidationException::withMessages(['status' => 'Only a draft legal text can be approved.']);
        }
        if ($text->created_by === $checker->id) {
            throw ValidationException::withMessages(['actor' => __('wave2.maker_checker')]);
        }

        return DB::transaction(function () use ($text, $checker) {
            $from = $text->effective_from->toDateString();
            $overlapping = ExclusionLegalText::where('exclusion_definition_id', $text->exclusion_definition_id)->where('status', 'APPROVED')->whereNull('superseded_at')
                ->where(fn ($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $from))->get();
            foreach ($overlapping as $old) {
                if ($old->effective_from->toDateString() >= $from) {
                    throw ValidationException::withMessages(['effective_from' => 'A later approved legal text already exists (v'.$old->version.').']);
                }
                $old->update(['effective_until' => $text->effective_from->copy()->subDay()->toDateString()]);
            }
            $text->update(['status' => 'APPROVED', 'approved_by' => $checker->id, 'approved_at' => now()]);
            $this->audit->record('catalogue.legal_text.approved', 'exclusion_legal_text', $text->id, ['version' => $text->version]);

            return $text->refresh();
        });
    }

    public function at(ExclusionDefinition $exclusion, string $date, string $timezone = 'Africa/Douala'): ?ExclusionLegalText
    {
        try {
            $resolved = $this->versions->resolve('exclusion_legal_text', ['exclusion_definition_id' => $exclusion->id], ReferenceInstant::at($date, $timezone));
        } catch (TemporalResolutionException $e) {
            if ($e->reasonCode === TemporalResolutionException::NO_VERSION) {
                return null;
            }

            throw $e;
        }

        return ExclusionLegalText::find($resolved->id);
    }
}
