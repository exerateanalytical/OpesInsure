<?php

declare(strict_types=1);

namespace App\Application\Documents\Security;

use App\Models\Document;

/**
 * Verification credentials of an issued document (crypto spec §9 and §12):
 *  - token: >= 128 bits of CSPRNG output, base64url, carried only in the QR; only its SHA-256 is stored
 *    (never an internal id, customer id or policy id);
 *  - short code: human-readable fallback, case-insensitive, ambiguity-resistant alphabet (no 0/O/1/I/L/U),
 *    non-sequential, last character is a check character. Format OV + 9 random + 1 check (12 chars, same
 *    length and prefix as the engine's earlier codes, which stay valid).
 */
final class VerificationCredentials
{
    public const ALPHABET = '23456789ABCDEFGHJKMNPQRSTVWXYZ';

    public static function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(max(16, (int) config('document_security.verification.token_bytes', 16)))), '+/', '-_'), '=');
    }

    public static function tokenHash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function newShortCode(): string
    {
        do {
            $body = 'OV';
            for ($i = 0; $i < 9; $i++) {
                $body .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            $code = $body.self::checkChar($body);
        } while (Document::where('verification_code', $code)->exists());

        return $code;
    }

    /** Weighted mod-30 check character over the code body (detects single substitutions and adjacent swaps). */
    public static function checkChar(string $body): string
    {
        $sum = 0;
        foreach (str_split(strtoupper($body)) as $i => $ch) {
            $v = strpos(self::ALPHABET, $ch);
            $sum += (($v === false ? ord($ch) : $v) * ($i + 1));
        }

        return self::ALPHABET[$sum % strlen(self::ALPHABET)];
    }

    /** Normalizes user input: case-insensitive, separators (spaces, dashes) ignored. */
    public static function normalize(string $code): string
    {
        return strtoupper((string) preg_replace('/[\s\-]+/', '', $code));
    }

    public static function checksumValid(string $code): bool
    {
        $code = self::normalize($code);

        return strlen($code) === 12 && self::checkChar(substr($code, 0, 11)) === $code[11];
    }

    /** Display form OVXX-XXXX-XXXX (printing only; lookup accepts both). */
    public static function display(string $code): string
    {
        return strlen($code) === 12 ? substr($code, 0, 4).'-'.substr($code, 4, 4).'-'.substr($code, 8, 4) : $code;
    }
}
