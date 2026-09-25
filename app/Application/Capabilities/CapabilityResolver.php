<?php

declare(strict_types=1);

namespace App\Application\Capabilities;

use App\Application\Capabilities\Models\CapabilityMode;
use App\Application\Capabilities\Models\CapabilityProfile;
use App\Domain\Shared\Clock\Clock;
use App\Models\DocumentIssuanceProfile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * REQ-AOM-001 — CapabilityResolver::mode(carrier, capability, product?, on).
 *
 * Precedence inside the profile effective at `on`: product override → class override
 * (insurance_products.line_code) → carrier default. POLICY_ISSUANCE and DOCUMENT_GENERATION
 * are read from the document engine's document_issuance_profiles (single source; REQ-DOC):
 * an explicit profile row is only used while it agrees with that issuance profile.
 * With nothing configured the insurer is MANUAL (AOM "Mode 1 minimum" — always supported).
 */
final class CapabilityResolver
{
    public const SOURCE_PROFILE = 'CAPABILITY_PROFILE';

    public const SOURCE_ISSUANCE = 'DOCUMENT_ISSUANCE_PROFILE';

    public const SOURCE_DEFAULT = 'DEFAULT';

    private const DEFAULT_MODES = ['POLICY_ISSUANCE' => 'MANUAL_UPLOAD_CARRIER', 'DOCUMENT_GENERATION' => 'CARRIER_ORIGINAL'];

    public function __construct(private readonly Clock $clock) {}

    public function profileAt(string $carrierId, ?\DateTimeInterface $on = null): ?CapabilityProfile
    {
        $at = $on ? CarbonImmutable::instance($on) : $this->clock->now();

        return CapabilityProfile::where('carrier_id', $carrierId)->whereIn('status', ['ACTIVE', 'SUPERSEDED'])
            ->where('effective_from', '<=', $at)
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', $at))
            ->orderByDesc('version')->first();
    }

    /**
     * @return array{capability:string,mode:string,execution_mode:string,source:string,scope:string,profile_id:?string,profile_version:?int,fallback_mode:?string,config:array}
     */
    public function mode(string $carrierId, string $capability, ?string $productId = null, ?\DateTimeInterface $on = null): array
    {
        if (! CapabilityCatalogue::has($capability)) {
            throw new InvalidArgumentException("Unknown capability {$capability}.");
        }
        $profile = $this->profileAt($carrierId, $on);
        $row = $profile ? $this->explicitRow($profile, $capability, $productId) : null;

        if (in_array($capability, CapabilityCatalogue::DERIVED_FROM_ISSUANCE_PROFILE, true)) {
            $issuance = $this->issuanceProfile($carrierId, $productId);
            if ($issuance !== null && ($row === null || ! self::agreesWithIssuance($capability, $row->mode, $issuance->issuance_mode))) {
                $mode = CapabilityCatalogue::ISSUANCE_PROFILE_MAP[$issuance->issuance_mode][$capability] ?? 'MANUAL';

                return $this->result($capability, $mode, self::SOURCE_ISSUANCE, $issuance->product_id ? 'PRODUCT' : 'CARRIER', $profile, null, ['document_issuance_profile_id' => $issuance->id]);
            }
        }
        if ($row !== null) {
            $scope = $row->scope_product_id ? 'PRODUCT' : ($row->scope_class_code ? 'CLASS' : 'CARRIER');

            return $this->result($capability, $row->mode, self::SOURCE_PROFILE, $scope, $profile, $row->fallback_mode, (array) $row->config);
        }

        return $this->result($capability, self::DEFAULT_MODES[$capability] ?? 'MANUAL', self::SOURCE_DEFAULT, 'PLATFORM', $profile, null, []);
    }

    /** @return array<string,array> every capability resolved at carrier-default (or product) scope */
    public function all(string $carrierId, ?string $productId = null, ?\DateTimeInterface $on = null): array
    {
        $out = [];
        foreach (array_keys(CapabilityCatalogue::CAPABILITIES) as $cap) {
            $out[$cap] = $this->mode($carrierId, $cap, $productId, $on);
        }

        return $out;
    }

    /**
     * Maturity is a descriptor of integration state, never a quality grade or a gate (AOM).
     *
     * @param  array<string,array{mode:string,execution_mode:string,source:string}>  $resolved
     * @return array{level:int,code:string}
     */
    public static function maturity(array $resolved, bool $hasActiveProfile): array
    {
        $exec = array_map(fn ($r) => $r['execution_mode'], $resolved);
        $set = array_filter($resolved, fn ($r) => $r['source'] !== self::SOURCE_DEFAULT);
        $level = 1;
        if ($hasActiveProfile && array_diff(CapabilityCatalogue::OPERATIONAL_CORE, array_keys($set)) === []) {
            $level = 2;
        }
        if (array_intersect(['CONFIGURED', 'HYBRID'], $exec) !== []) {
            $level = 3;
        }
        if (in_array('REMOTE_API', $exec, true)) {
            $level = 4;
        }
        if (array_filter(CapabilityCatalogue::INTEGRATED_CORE, fn ($c) => ($exec[$c] ?? null) !== 'REMOTE_API') === []) {
            $level = 5;
        }

        return ['level' => $level, 'code' => CapabilityCatalogue::MATURITY[$level]];
    }

    public function issuanceProfile(string $carrierId, ?string $productId): ?DocumentIssuanceProfile
    {
        $q = DocumentIssuanceProfile::where('carrier_id', $carrierId)->where('status', 'ACTIVE');
        if ($productId !== null) {
            $specific = (clone $q)->where('product_id', $productId)->first();
            if ($specific) {
                return $specific;
            }
        }

        return $q->whereNull('product_id')->first();
    }

    public static function agreesWithIssuance(string $capability, string $mode, string $issuanceMode): bool
    {
        $family = match (true) {
            in_array($mode, ['MANUAL', 'MANUAL_UPLOAD_CARRIER', 'MANUAL_UPLOAD_BROKER'], true) => ['MANUAL_UPLOAD'],
            $mode === 'CARRIER_ORIGINAL' => ['MANUAL_UPLOAD', 'INSURER_API'],
            in_array($mode, ['CONFIGURED', 'OPES_GENERATED', 'OPES_TEMPLATE'], true) => ['OPES_GENERATED'],
            in_array($mode, ['REMOTE_API', 'INSURER_API'], true) => ['INSURER_API'],
            $mode === 'HYBRID' => ['HYBRID'],
            default => [],
        };

        return in_array($issuanceMode, $family, true);
    }

    private function explicitRow(CapabilityProfile $profile, string $capability, ?string $productId): ?CapabilityMode
    {
        $rows = CapabilityMode::where('profile_id', $profile->id)->where('capability', $capability)->get();
        if ($productId !== null) {
            if ($hit = $rows->firstWhere('scope_product_id', $productId)) {
                return $hit;
            }
            $class = DB::table('insurance_products')->where('id', $productId)->value('line_code');
            if ($class !== null && ($hit = $rows->first(fn ($r) => $r->scope_product_id === null && $r->scope_class_code === $class))) {
                return $hit;
            }
        }

        return $rows->first(fn ($r) => $r->scope_product_id === null && $r->scope_class_code === null);
    }

    private function result(string $capability, string $mode, string $source, string $scope, ?CapabilityProfile $profile, ?string $fallback, array $config): array
    {
        return [
            'capability' => $capability,
            'mode' => $mode,
            'execution_mode' => CapabilityCatalogue::executionMode($capability, $mode) ?? 'MANUAL',
            'source' => $source,
            'scope' => $scope,
            'profile_id' => $profile?->id,
            'profile_version' => $profile?->version,
            'fallback_mode' => $fallback,
            'config' => $config,
        ];
    }
}
