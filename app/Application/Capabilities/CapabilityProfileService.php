<?php

declare(strict_types=1);

namespace App\Application\Capabilities;

use App\Application\Audit\AuditWriter;
use App\Application\Capabilities\Models\CapabilityMode;
use App\Application\Capabilities\Models\CapabilityProfile;
use App\Application\Documents\Engine\DocumentEngine;
use App\Domain\Shared\Clock\Clock;
use App\Models\Carrier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * REQ-AOM-001 — capability profile lifecycle DRAFT → SUBMITTED → ACTIVE (→ SUPERSEDED) | REJECTED.
 * Versioned and never overwritten; maker-checker (the maker cannot approve); incoherent
 * mode combinations are rejected at submission (plan A2 "Validation").
 */
final class CapabilityProfileService
{
    public function __construct(
        private readonly AuditWriter $audit,
        private readonly Clock $clock,
        private readonly CapabilityResolver $resolver,
    ) {}

    /** @param list<array<string,mixed>> $modes */
    public function draft(Carrier $carrier, array $modes, User $maker, ?string $notes = null): CapabilityProfile
    {
        return DB::transaction(function () use ($carrier, $modes, $maker, $notes) {
            $open = CapabilityProfile::where('carrier_id', $carrier->id)->whereIn('status', ['DRAFT', 'SUBMITTED'])->lockForUpdate()->exists();
            if ($open) {
                throw ValidationException::withMessages(['profile' => 'A draft or submitted capability profile already exists for this insurer; edit or decide it first.']);
            }
            $version = (int) CapabilityProfile::where('carrier_id', $carrier->id)->max('version') + 1;
            $profile = CapabilityProfile::create(['carrier_id' => $carrier->id, 'version' => $version, 'status' => 'DRAFT', 'created_by' => $maker->id, 'notes' => $notes]);
            $this->writeModes($profile, $modes);
            $this->audit->record('capability_profile.drafted', 'carrier_capability_profile', $profile->id, ['carrier_id' => $carrier->id, 'version' => $version, 'modes' => count($modes)]);

            return $profile->load('modes');
        });
    }

    /** @param list<array<string,mixed>> $modes */
    public function replaceModes(CapabilityProfile $profile, array $modes, User $actor): CapabilityProfile
    {
        $this->assertStatus($profile, ['DRAFT']);

        return DB::transaction(function () use ($profile, $modes, $actor) {
            $old = $profile->modes()->get(['capability', 'mode', 'scope_product_id', 'scope_class_code'])->toArray();
            $profile->modes()->delete();
            $this->writeModes($profile, $modes);
            $this->audit->recordChange('capability_profile.modes_replaced', 'carrier_capability_profile', $profile->id, ['modes' => $old], ['modes' => $modes, 'by' => $actor->id], 'DRAFT_EDIT');

            return $profile->refresh()->load('modes');
        });
    }

    public function submit(CapabilityProfile $profile, User $actor): CapabilityProfile
    {
        $this->assertStatus($profile, ['DRAFT']);
        $problems = $this->incoherences($profile);
        if ($problems !== []) {
            throw ValidationException::withMessages(['modes' => $problems]);
        }

        return $this->transition($profile, 'SUBMITTED', ['submitted_by' => $actor->id, 'submitted_at' => $this->clock->now()], 'capability_profile.submitted');
    }

    public function approve(CapabilityProfile $profile, User $checker): CapabilityProfile
    {
        $this->assertStatus($profile, ['SUBMITTED']);
        if (in_array($checker->id, [$profile->created_by, $profile->submitted_by], true)) {
            throw ValidationException::withMessages(['approver' => 'Maker-checker: the maker of a capability profile cannot approve it.']);
        }
        $problems = $this->incoherences($profile);
        if ($problems !== []) {
            throw ValidationException::withMessages(['modes' => $problems]);
        }

        return DB::transaction(function () use ($profile, $checker) {
            $now = $this->clock->now();
            $previous = CapabilityProfile::where('carrier_id', $profile->carrier_id)->where('status', 'ACTIVE')->lockForUpdate()->first();
            if ($previous) {
                $previous->forceFill(['status' => 'SUPERSEDED', 'effective_until' => $now])->save();
                $this->audit->record('capability_profile.superseded', 'carrier_capability_profile', $previous->id, ['by_version' => $profile->version]);
            }
            $profile->forceFill(['status' => 'ACTIVE', 'approved_by' => $checker->id, 'approved_at' => $now, 'effective_from' => $now])->save();
            $m = CapabilityResolver::maturity($this->resolver->all($profile->carrier_id, null, $now->addSecond()), true);
            $profile->forceFill(['maturity_level' => $m['level'], 'maturity_code' => $m['code']])->save();
            $this->audit->record('capability_profile.approved', 'carrier_capability_profile', $profile->id, ['version' => $profile->version, 'maturity' => $m['code']]);

            return $profile->refresh()->load('modes');
        });
    }

    public function reject(CapabilityProfile $profile, User $checker, string $reason): CapabilityProfile
    {
        $this->assertStatus($profile, ['SUBMITTED']);

        return $this->transition($profile, 'REJECTED', ['rejection_reason' => $reason, 'approved_by' => $checker->id], 'capability_profile.rejected', $reason);
    }

    /** @return list<string> human-readable incoherences; [] when the profile may be submitted/approved */
    public function incoherences(CapabilityProfile $profile): array
    {
        $out = [];
        foreach ($profile->modes()->get() as $row) {
            $label = $row->capability.($row->scope_product_id ? " (product {$row->scope_product_id})" : ($row->scope_class_code ? " (class {$row->scope_class_code})" : ''));
            $exec = $row->execution_mode;

            if ($row->capability === 'RATING' && $exec === 'CONFIGURED') {
                $q = DB::table('tariff_versions as t')->join('insurance_products as p', 'p.id', '=', 't.insurance_product_id')
                    ->where('p.carrier_id', $profile->carrier_id)->where('t.status', 'APPROVED');
                if ($row->scope_product_id) {
                    $q->where('p.id', $row->scope_product_id);
                } elseif ($row->scope_class_code) {
                    $q->where('p.line_code', $row->scope_class_code);
                }
                if (! $q->exists()) {
                    $out[] = "{$label}: {$row->mode} pricing requires an APPROVED tariff version.";
                }
            }

            if ($exec === 'REMOTE_API' && $row->capability !== 'API_INTEGRATION') {
                $client = $row->config['integration_client_id'] ?? null;
                if (! is_string($client) || ! DB::table('integration_clients')->where('id', $client)->where('status', 'ACTIVE')->exists()) {
                    $out[] = "{$label}: {$row->mode} requires config.integration_client_id pointing to an ACTIVE integration client.";
                }
            }

            if (in_array($row->capability, CapabilityCatalogue::DERIVED_FROM_ISSUANCE_PROFILE, true)) {
                $issuance = $this->resolver->issuanceProfile($profile->carrier_id, $row->scope_product_id);
                if ($issuance !== null && ! CapabilityResolver::agreesWithIssuance($row->capability, $row->mode, $issuance->issuance_mode)) {
                    $out[] = "{$label}: {$row->mode} contradicts the document issuance profile ({$issuance->issuance_mode}); change it in the document engine instead.";
                }
                if (in_array($exec, ['CONFIGURED', 'HYBRID'], true) && ! DocumentEngine::mayRenderForInsurer($issuance)) {
                    $out[] = "{$label}: {$row->mode} requires an ACTIVE document issuance profile authorizing OpesInsure rendering.";
                }
            }
        }

        return $out;
    }

    /** @param list<array<string,mixed>> $modes */
    private function writeModes(CapabilityProfile $profile, array $modes): void
    {
        $errors = [];
        $seen = [];
        foreach (array_values($modes) as $i => $m) {
            $cap = (string) ($m['capability'] ?? '');
            $mode = (string) ($m['mode'] ?? '');
            if (! CapabilityCatalogue::has($cap)) {
                $errors["modes.$i.capability"] = "Unknown capability {$cap}.";

                continue;
            }
            $exec = CapabilityCatalogue::executionMode($cap, $mode);
            if ($exec === null) {
                $errors["modes.$i.mode"] = "Mode {$mode} is not valid for {$cap}. Allowed: ".implode(', ', CapabilityCatalogue::modes($cap));

                continue;
            }
            $fallback = $m['fallback_mode'] ?? null;
            if ($fallback !== null && CapabilityCatalogue::executionMode($cap, (string) $fallback) === null) {
                $errors["modes.$i.fallback_mode"] = "Fallback {$fallback} is not valid for {$cap}.";
            }
            $product = $m['scope_product_id'] ?? null;
            if ($product !== null && ! DB::table('insurance_products')->where('id', $product)->where('carrier_id', $profile->carrier_id)->exists()) {
                $errors["modes.$i.scope_product_id"] = 'The product override must be one of this insurer\'s products.';
            }
            $key = $cap.'|'.($product ?? '').'|'.($m['scope_class_code'] ?? '');
            if (isset($seen[$key])) {
                $errors["modes.$i"] = "Duplicate mode for {$cap} at the same scope.";
            }
            $seen[$key] = true;
            if ($errors === []) {
                CapabilityMode::create([
                    'profile_id' => $profile->id, 'capability' => $cap, 'mode' => $mode, 'execution_mode' => $exec,
                    'scope_product_id' => $product, 'scope_class_code' => $m['scope_class_code'] ?? null,
                    'config' => (array) ($m['config'] ?? []), 'fallback_mode' => $fallback,
                ]);
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function transition(CapabilityProfile $profile, string $to, array $attrs, string $action, ?string $reason = null): CapabilityProfile
    {
        return DB::transaction(function () use ($profile, $to, $attrs, $action, $reason) {
            $from = $profile->status;
            $profile->forceFill(['status' => $to] + $attrs)->save();
            $this->audit->record($action, 'carrier_capability_profile', $profile->id, ['from' => $from, 'to' => $to], $reason);

            return $profile->refresh()->load('modes');
        });
    }

    private function assertStatus(CapabilityProfile $profile, array $allowed): void
    {
        if (! in_array($profile->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => "Capability profile v{$profile->version} is {$profile->status}; expected ".implode('/', $allowed).'.']);
        }
    }
}
