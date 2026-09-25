<?php

declare(strict_types=1);

namespace App\Application\Directory;

use App\Application\Audit\AuditWriter;
use App\Models\Carrier;
use App\Models\Directory\InstitutionOffice;
use App\Models\Directory\InstitutionProfile;
use App\Models\Directory\InstitutionVerificationLabel;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Admin edits of the official insurer directory. Every change is audited with
 * old/new values and the directory provenance (sources, dataset); the edit is
 * marked (admin_edited_at/by) so the deploy seeder never overwrites it.
 * Carrier identity, names and authorizations are never touched here.
 */
final class InstitutionDirectoryService
{
    public function __construct(private readonly AuditWriter $audit) {}

    /**
     * @param  array{verification_status?: ?string, website?: ?string, po_box?: ?string, phones?: list<string>, emails?: list<string>, hq_city?: ?string, hq_address?: ?string, branches?: list<array{name: string, type: string, city?: ?string, address?: ?string, phone?: ?string}>}  $data
     */
    public function update(Carrier $carrier, array $data, User $actor, string $reason): InstitutionProfile
    {
        if (! $carrier->is_official_register) {
            throw ValidationException::withMessages(['carrier' => 'Only official register insurers have a directory profile.']);
        }
        if (blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'A reason is required.']);
        }
        if (($data['verification_status'] ?? null) !== null && ! in_array($data['verification_status'], InstitutionProfile::STATUSES, true)) {
            throw ValidationException::withMessages(['verification_status' => 'Unknown verification status.']);
        }
        foreach ($data['branches'] ?? [] as $b) {
            if (! in_array($b['type'] ?? null, InstitutionOffice::TYPES, true) || blank($b['name'] ?? null)) {
                throw ValidationException::withMessages(['branches' => 'Each branch needs a name and a type (HEAD_OFFICE or DIRECT_BRANCH).']);
            }
        }
        if (collect($data['branches'] ?? [])->pluck('name')->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['branches' => 'Branch names must be unique per insurer.']);
        }

        return DB::transaction(function () use ($carrier, $data, $actor, $reason) {
            $profile = InstitutionProfile::firstOrNew(['carrier_id' => $carrier->id], [
                'directory_id' => $carrier->canonical_id, 'dataset' => 'ADMIN', 'dataset_version' => '-', 'sources' => [], 'phones' => [], 'emails' => [],
            ]);
            $old = $this->snapshot($carrier, $profile);

            $profile->fill(collect($data)->only(['verification_status', 'website', 'po_box'])->map(fn ($v) => filled($v) ? $v : null)->all());
            foreach (['phones', 'emails'] as $k) {
                if (array_key_exists($k, $data)) {
                    $profile->{$k} = array_values(array_filter(array_map(fn ($v) => trim((string) $v), $data[$k] ?? []), 'filled'));
                }
            }
            $profile->admin_edited_at = now();
            $profile->admin_edited_by = $actor->id;
            $profile->save();

            if (array_key_exists('hq_city', $data) && $carrier->party_id && filled($data['hq_city'])) {
                $values = ['city' => $data['hq_city'], 'line1' => $data['hq_address'] ?? null, 'updated_at' => now()];
                $existing = DB::table('party_addresses')->where('party_id', $carrier->party_id)->where('type', 'HEAD_OFFICE')->first();
                if ($existing) {
                    DB::table('party_addresses')->where('id', $existing->id)->update($values);
                } else {
                    DB::table('party_addresses')->insert($values + ['id' => (string) Str::uuid(), 'party_id' => $carrier->party_id, 'type' => 'HEAD_OFFICE', 'country_code' => 'CM',
                        'is_primary' => ! DB::table('party_addresses')->where('party_id', $carrier->party_id)->where('is_primary', true)->exists(), 'created_at' => now()]);
                }
            }

            if (array_key_exists('branches', $data)) {
                $keep = [];
                foreach (array_values($data['branches'] ?? []) as $i => $b) {
                    $existing = InstitutionOffice::where('carrier_id', $carrier->id)->where('name', $b['name'])->first();
                    $office = InstitutionOffice::updateOrCreate(['carrier_id' => $carrier->id, 'name' => $b['name']], [
                        'office_type' => $b['type'], 'city' => $b['city'] ?? null, 'address' => $b['address'] ?? null, 'phone' => $b['phone'] ?? null,
                        'sort_order' => $i + 1, 'source' => $existing?->source ?? 'ADMIN',
                    ]);
                    $keep[] = $office->id;
                }
                // Removing an office is an explicit, audited admin edit of directory data (never register data).
                InstitutionOffice::where('carrier_id', $carrier->id)->whereNotIn('id', $keep)->delete();
            }

            $this->audit->record('institution_directory.updated', 'carrier', $carrier->id, [
                'directory_id' => $profile->directory_id, 'sources' => $profile->sources, 'dataset' => $profile->dataset, 'dataset_version' => $profile->dataset_version, 'reason' => $reason,
            ], 'ADMIN_EDIT', ['old' => $old, 'new' => $this->snapshot($carrier, $profile->refresh())]);
            $this->flushPublicCache();

            return $profile;
        });
    }

    public function updateLabel(string $code, string $en, string $fr, User $actor): InstitutionVerificationLabel
    {
        if (! array_key_exists($code, InstitutionVerificationLabel::DEFAULTS)) {
            throw ValidationException::withMessages(['code' => 'Unknown verification status.']);
        }
        if (blank($en) || blank($fr)) {
            throw ValidationException::withMessages(['label' => 'Both EN and FR labels are required.']);
        }

        return DB::transaction(function () use ($code, $en, $fr, $actor) {
            $label = InstitutionVerificationLabel::find($code);
            $old = $label ? ['en' => $label->label_en, 'fr' => $label->label_fr] : null;
            $label = InstitutionVerificationLabel::updateOrCreate(['code' => $code], ['label_en' => trim($en), 'label_fr' => trim($fr), 'updated_by' => $actor->id]);
            $this->audit->record('institution_directory.label_updated', 'institution_verification_label', null, ['code' => $code], null, ['old' => $old, 'new' => ['en' => $label->label_en, 'fr' => $label->label_fr]]);
            $this->flushPublicCache();

            return $label;
        });
    }

    public function flushPublicCache(): void
    {
        foreach (['all', 'stats'] as $key) {
            Cache::forget('public_site.directory.'.$key);
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(Carrier $carrier, InstitutionProfile $p): array
    {
        $hq = $carrier->party_id ? DB::table('party_addresses')->where('party_id', $carrier->party_id)->where('type', 'HEAD_OFFICE')->first(['city', 'line1']) : null;

        return [
            'verification_status' => $p->verification_status, 'website' => $p->website, 'po_box' => $p->po_box, 'phones' => $p->phones ?? [], 'emails' => $p->emails ?? [],
            'hq' => $hq ? ['city' => $hq->city, 'address' => $hq->line1] : null,
            'branches' => InstitutionOffice::where('carrier_id', $carrier->id)->orderBy('sort_order')->get(['name', 'office_type', 'city', 'address', 'phone'])->toArray(),
        ];
    }
}
