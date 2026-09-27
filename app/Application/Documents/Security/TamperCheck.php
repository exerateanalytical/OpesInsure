<?php

declare(strict_types=1);

namespace App\Application\Documents\Security;

use App\Application\Audit\AuditWriter;
use App\Models\Document;

/**
 * Staff tamper check (crypto spec §14): a PDF someone presents is hashed and compared with the registry.
 *
 *  - against(): compares the file with ONE registry record (its stored SHA-256 and the platform signature,
 *    whose signed payload carries the final file hash).
 *  - identify(): finds the registry record whose SHA-256 equals the file (a byte-identical original), or none.
 *
 * Verdict: AUTHENTIC (hash matches and the signature is VALID or the document is unsigned), TAMPERED (hash differs
 * from the record), SIGNATURE_INVALID (hash matches but the stored signature does not verify), UNKNOWN (no record has
 * this hash). Only hashes are audited, never the file.
 */
final class TamperCheck
{
    public const VERDICTS = ['AUTHENTIC', 'TAMPERED', 'SIGNATURE_INVALID', 'UNKNOWN'];

    public function __construct(private DocumentSigner $signer, private AuditWriter $audit) {}

    /** @return array{verdict: string, file_sha256: string, registry_sha256: ?string, hash: string, signature: string, signed_hash: string, document_id: ?string, document_number: ?string} */
    public function against(Document $document, string $bytes): array
    {
        $hash = hash('sha256', $bytes);
        $sig = is_array($document->signature) ? $document->signature : json_decode((string) $document->getRawOriginal('signature'), true);
        $sig = is_array($sig) ? $sig : null;
        $signature = $this->signer->verify($sig);
        $signedHash = ($sig['status'] ?? null) === 'SIGNED' ? (hash_equals((string) ($sig['payload']['final_file_hash'] ?? ''), $hash) ? 'MATCH' : 'MISMATCH') : 'NOT_SIGNED';
        $hashResult = $document->sha256 !== null && hash_equals((string) $document->sha256, $hash) ? 'MATCH' : 'MISMATCH';

        $verdict = match (true) {
            $hashResult === 'MISMATCH' || $signedHash === 'MISMATCH' => 'TAMPERED',
            in_array($signature, ['SIGNATURE_INVALID', 'UNVERIFIABLE'], true) => 'SIGNATURE_INVALID',
            default => 'AUTHENTIC',
        };

        return $this->record([
            'verdict' => $verdict, 'file_sha256' => $hash, 'registry_sha256' => $document->sha256, 'hash' => $hashResult,
            'signature' => $signature, 'signed_hash' => $signedHash, 'document_id' => $document->id, 'document_number' => $document->document_number,
        ]);
    }

    /** @return array<string, mixed> */
    public function identify(string $bytes, ?callable $visible = null): array
    {
        $hash = hash('sha256', $bytes);
        $doc = Document::where('sha256', $hash)->orderByDesc('created_at')->first();
        if ($doc && ($visible === null || $visible($doc))) {
            return $this->against($doc, $bytes);
        }

        return $this->record(['verdict' => 'UNKNOWN', 'file_sha256' => $hash, 'registry_sha256' => null, 'hash' => 'NO_RECORD',
            'signature' => 'NOT_CHECKED', 'signed_hash' => 'NOT_CHECKED', 'document_id' => null, 'document_number' => null]);
    }

    /** One-line human summary (FR / EN). */
    public static function summary(array $r): string
    {
        return match ($r['verdict']) {
            'AUTHENTIC' => 'Authentic: the file matches the registry'.($r['signature'] === 'VALID' ? ' and the platform signature is valid' : '').'. / Authentique : le fichier correspond au registre.',
            'TAMPERED' => 'Tampered: the file differs from the registry original. / Falsifié : le fichier diffère de l\'original du registre.',
            'SIGNATURE_INVALID' => 'The file matches but the stored signature does not verify. / Le fichier correspond mais la signature ne se vérifie pas.',
            default => 'Unknown: no issued document has this fingerprint. / Inconnu : aucun document émis n\'a cette empreinte.',
        };
    }

    /** @param array<string, mixed> $r */
    private function record(array $r): array
    {
        rescue(fn () => $this->audit->record('document.tamper_check', 'document', $r['document_id'], [
            'verdict' => $r['verdict'], 'file_sha256' => $r['file_sha256'], 'hash' => $r['hash'], 'signature' => $r['signature'],
        ]), null, false);

        return $r;
    }
}
