<?php

declare(strict_types=1);

namespace App\Application\Integrations\Developer;

use Laravel\Passport\Passport;
use RuntimeException;

/**
 * REQ-IAM-004 — OAuth 2.1 / OIDC discovery metadata and the JWKS derived from Passport's own RSA
 * signing key (the key Passport signs every access token with), so relying parties can verify tokens
 * without a shared secret. Only endpoints that really exist are advertised (Passport's /oauth/token and
 * /oauth/authorize); no userinfo endpoint is claimed because none is implemented.
 */
final class OidcDiscoveryService
{
    public function configuration(): array
    {
        $issuer = rtrim((string) config('app.url'), '/');

        return [
            'issuer' => $issuer,
            'authorization_endpoint' => $issuer.'/oauth/authorize',
            'token_endpoint' => $issuer.'/oauth/token',
            'jwks_uri' => $issuer.'/.well-known/jwks.json',
            'scopes_supported' => OAuthScopeCatalogue::scopes(),
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'client_credentials', 'refresh_token'],
            'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post'],
            'code_challenge_methods_supported' => ['S256'],
            'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => ['RS256'],
            'token_endpoint_auth_signing_alg_values_supported' => ['RS256'],
            'service_documentation' => $issuer.'/api/v1/developer/openapi.json',
        ];
    }

    /** @return array{keys: list<array<string,string>>} */
    public function jwks(): array
    {
        $pem = $this->publicKeyPem();
        $key = openssl_pkey_get_public($pem);
        $details = $key ? openssl_pkey_get_details($key) : false;
        if (! $details || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) {
            throw new RuntimeException('Passport public key is missing or not an RSA key.');
        }
        $n = self::b64url($details['rsa']['n']);
        $e = self::b64url($details['rsa']['e']);
        // RFC 7638 JWK thumbprint as the key id.
        $kid = self::b64url(hash('sha256', json_encode(['e' => $e, 'kty' => 'RSA', 'n' => $n], JSON_UNESCAPED_SLASHES), true));

        return ['keys' => [['kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256', 'kid' => $kid, 'n' => $n, 'e' => $e]]];
    }

    private function publicKeyPem(): string
    {
        $configured = config('passport.public_key');
        if (is_string($configured) && $configured !== '') {
            return str_replace('\\n', "\n", $configured);
        }
        $path = Passport::keyPath('oauth-public.key');
        if (! is_file($path)) {
            throw new RuntimeException('Passport public key not found.');
        }

        return (string) file_get_contents($path);
    }

    private static function b64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }
}
