<?php

declare(strict_types=1);

namespace App\Application\Integrations\Activa;

/**
 * The four Activa APIs behind the Azure API Management gateway (docs/integrations/activa/operations_2026-09-29.json)
 * and how each one authenticates. Every call carries Ocp-Apim-Subscription-Key plus the bearer token of that service.
 */
final class ActivaServices
{
    public const TRAVEL = 'travel';            // activa-cameroun-travel-api (cmr-travel)

    public const PRICING = 'pricing';          // tarifiktor-cmr-test

    public const SUBSCRIPTION = 'subscription'; // souscription-cmr-test

    public const DOCUMENTS = 'documents';      // docgenerator-cmr-test

    public const ALL = [self::TRAVEL, self::PRICING, self::SUBSCRIPTION, self::DOCUMENTS];

    /** Login fields per service (as entered in the admin form). */
    public const CREDENTIAL_FIELDS = [
        self::TRAVEL => ['client_id', 'client_secret', 'scope'],
        self::PRICING => ['email', 'password'],
        self::SUBSCRIPTION => ['user_id', 'password'],
        self::DOCUMENTS => ['user_id', 'password'],
    ];

    /** Fields that must be present for the service to be usable (scope is optional). */
    public const REQUIRED_FIELDS = [
        self::TRAVEL => ['client_id', 'client_secret'],
        self::PRICING => ['email', 'password'],
        self::SUBSCRIPTION => ['user_id', 'password'],
        self::DOCUMENTS => ['user_id', 'password'],
    ];

    /** Never shown back, never logged. */
    public const SECRET_FIELDS = ['client_secret', 'password', 'subscription_key'];

    /**
     * Auth operation of a service.
     *
     * @param  array<string, string>  $credentials
     * @return array{operation: string, path: string, form?: array<string, string>, json?: array<string, string>}
     */
    public static function auth(string $service, array $credentials, string $apiVersion): array
    {
        return match ($service) {
            // POST /token — application/x-www-form-urlencoded (grant_type, scope); OAuth2 client credentials.
            self::TRAVEL => ['operation' => 'getAccessToken', 'path' => '/token', 'form' => array_filter([
                'grant_type' => 'client_credentials', 'scope' => $credentials['scope'] ?? null,
                'client_id' => $credentials['client_id'] ?? null, 'client_secret' => $credentials['client_secret'] ?? null,
            ], fn ($v) => $v !== null && $v !== '')],
            // POST /api/Account/Authentication — UserForAuthenticationDto {email, password}.
            self::PRICING => ['operation' => 'post-api-account-authentication', 'path' => '/api/Account/Authentication',
                'json' => ['email' => (string) ($credentials['email'] ?? ''), 'password' => (string) ($credentials['password'] ?? '')]],
            // POST /api/v{version}/Authentication/Authenticate — {userId, password}.
            self::SUBSCRIPTION => ['operation' => 'post-api-v-version-authentication-authenticate', 'path' => "/api/v{$apiVersion}/Authentication/Authenticate",
                'json' => ['userId' => (string) ($credentials['user_id'] ?? ''), 'password' => (string) ($credentials['password'] ?? '')]],
            // POST /api/Authentication/Authenticate — UsersDto {userId, password}.
            self::DOCUMENTS => ['operation' => 'post-api-authentication-authenticate', 'path' => '/api/Authentication/Authenticate',
                'json' => ['userId' => (string) ($credentials['user_id'] ?? ''), 'password' => (string) ($credentials['password'] ?? '')]],
            default => throw new \InvalidArgumentException("Unknown Activa service {$service}."),
        };
    }
}
