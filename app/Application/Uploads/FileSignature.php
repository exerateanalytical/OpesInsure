<?php

declare(strict_types=1);

namespace App\Application\Uploads;

/**
 * The one magic-byte check for mobile uploads (security review 2026-09-27, item 6). A declared MIME type is only
 * accepted when the file's leading bytes are that type's signature, so a renamed executable or HTML file cannot pass
 * as a JPEG/PDF. Used by MobileDocumentService (base64 uploads), ResumableUploadService::finalize() (chunked uploads)
 * and MobileClaimEvidenceService (registering a finished upload session as claim evidence).
 */
final class FileSignature
{
    /** Declared MIME types a mobile upload may carry. */
    public const ALLOWED = ['application/pdf', 'image/jpeg', 'image/png', 'video/mp4'];

    public static function matches(string $head, string $mimeType): bool
    {
        return match ($mimeType) {
            'application/pdf' => str_starts_with($head, '%PDF-'),
            'image/jpeg' => str_starts_with($head, "\xFF\xD8\xFF"),
            'image/png' => str_starts_with($head, "\x89PNG\x0D\x0A\x1A\x0A"),
            // ISO base media: bytes 4..7 are the 'ftyp' box type.
            'video/mp4' => strlen($head) >= 12 && substr($head, 4, 4) === 'ftyp',
            default => false,
        };
    }

    public static function fileMatches(string $absolutePath, string $mimeType): bool
    {
        $handle = @fopen($absolutePath, 'rb');
        if ($handle === false) {
            return false;
        }
        $head = (string) fread($handle, 16);
        fclose($handle);

        return self::matches($head, $mimeType);
    }
}
