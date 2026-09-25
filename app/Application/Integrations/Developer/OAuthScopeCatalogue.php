<?php

declare(strict_types=1);

namespace App\Application\Integrations\Developer;

use App\Application\Identity\Rbac\PermissionCatalogue;

/**
 * REQ-IAM-004 — the OAuth scopes a client-credentials (partner) token may carry, each mapped to the
 * permission strings it stands for. Every mapped permission MUST exist in the governed permission
 * catalogue (PermissionCatalogue::all()) — enforced by tests/Feature/Batch16/DeveloperPlatform — so a
 * scope can never grant something the RBAC model does not know about. This is the single list used by
 * Passport::tokensCan(), client registration validation, OIDC discovery and the OpenAPI generator.
 */
final class OAuthScopeCatalogue
{
    /** @var array<string, array{description:string, permissions:list<string>}> */
    public const SCOPES = [
        'quotes.read' => ['description' => 'Read quote requests and offers', 'permissions' => ['quotes.read']],
        'quotes.write' => ['description' => 'Submit quote requests', 'permissions' => ['quotes.manage', 'quotes.rate']],
        'policies.read' => ['description' => 'Read policy and certificate data', 'permissions' => ['policies.read']],
        'policies.write' => ['description' => 'Submit issuance, endorsement and cancellation requests', 'permissions' => ['policies.cancellation.request', 'policies.reinstatement.request']],
        'claims.read' => ['description' => 'Read claim status', 'permissions' => ['claims.view']],
        'claims.write' => ['description' => 'Submit FNOL and claim updates', 'permissions' => ['claims.evidence.manage']],
        'settlements.read' => ['description' => 'Read settlement and bordereau data', 'permissions' => ['settlement.read', 'bordereaux.view']],
        'webhooks.manage' => ['description' => 'Manage webhook subscriptions', 'permissions' => ['integrations.manage']],
    ];

    /** @return list<string> */
    public static function scopes(): array
    {
        return array_keys(self::SCOPES);
    }

    /** @return array<string,string> scope => description (Passport::tokensCan shape) */
    public static function descriptions(): array
    {
        return array_map(static fn (array $s) => $s['description'], self::SCOPES);
    }

    /** @param list<string> $scopes @return list<string> */
    public static function permissionsFor(array $scopes): array
    {
        $out = [];
        foreach ($scopes as $scope) {
            $defs = $scope === '*' ? array_values(self::SCOPES) : (isset(self::SCOPES[$scope]) ? [self::SCOPES[$scope]] : []);
            foreach ($defs as $def) {
                array_push($out, ...$def['permissions']);
            }
        }
        $out = array_values(array_unique($out));
        sort($out);

        return $out;
    }

    /** Permissions referenced by a scope but missing from the governed catalogue (must be empty). @return list<string> */
    public static function unknownPermissions(): array
    {
        $catalogue = PermissionCatalogue::all();

        return array_values(array_filter(self::permissionsFor(['*']), static fn (string $p) => ! isset($catalogue[$p])));
    }
}
