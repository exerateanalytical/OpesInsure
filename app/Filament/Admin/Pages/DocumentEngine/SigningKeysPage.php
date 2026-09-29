<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\DocumentEngine;

use App\Application\Documents\Security\DocumentSigner;
use App\Filament\Admin\Resources\DocumentNumberingFamilies\DocumentNumberingFamilyResource;
use App\Models\Document;
use App\Models\DocumentNumberingFamily;
use BackedEnum;

/**
 * Read-only view of the platform signing keys (key id, public key, status) and the document numbering families.
 * The secret key is never read into the page: only DocumentSigner::publicKeys() (the same material published at
 * /api/v1/public/document-signing-keys) and the configuration issue text. Keys are provisioned on the server.
 */
final class SigningKeysPage extends DocumentEngineReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-key-round';

    public static function getNavigationLabel(): string
    {
        return __('Signing keys & numbering');
    }

    protected static ?int $navigationSort = 310;

    protected static ?string $slug = 'document-engine/signing-keys';

    public function getTitle(): string
    {
        return __('Signing keys and numbering families');
    }

    /** @return list<array{key_id: string, public_key: string, fingerprint: string, status: string, signed_documents: int}> */
    public static function keys(): array
    {
        $signer = app(DocumentSigner::class);
        $current = $signer->configured() ? (string) config('document_security.signing.key_id') : null;

        return collect($signer->publicKeys())->map(fn (string $b64, $id) => [
            'key_id' => (string) $id, 'public_key' => $b64, 'fingerprint' => hash('sha256', (string) base64_decode($b64, true)),
            'status' => (string) $id === $current ? 'ACTIVE' : 'RETIRED',
            'signed_documents' => Document::whereRaw("signature->>'key_id' = ?", [(string) $id])->count(),
        ])->values()->all();
    }

    protected function report(): array
    {
        $signer = app(DocumentSigner::class);
        $issue = $signer->configurationIssue();
        $keys = self::keys();

        return [
            'cards' => [
                ['Signing', $issue === null ? 'CONFIGURED' : 'CONFIG_REQUIRED', $issue],
                ['Algorithm', 'ED25519', 'Detached signature over the canonical payload'],
                ['Keys published', count($keys), '/api/v1/public/document-signing-keys'],
                ['Numbering families', DocumentNumberingFamily::count(), null],
            ],
            'sections' => [
                ['title' => 'Signing keys', 'description' => 'Read-only. Keys are generated and rotated on the server; the secret key never leaves it.',
                    'headers' => ['Key id', 'Status', 'Public key (base64)', 'SHA-256 fingerprint', 'Signed documents'],
                    'rows' => array_map(fn ($k) => [$k['key_id'], $k['status'], $k['public_key'], $k['fingerprint'], $k['signed_documents']], $keys)],
                ['title' => 'Document numbering families', 'description' => 'Edit a family in '.DocumentNumberingFamilyResource::getNavigationLabel().'.',
                    'headers' => ['Family', 'Prefix', 'Year', 'Pad', 'Document types', 'Status'],
                    'rows' => DocumentNumberingFamily::orderBy('family_code')->get()->map(fn ($f) => [$f->family_code, $f->prefix, (bool) $f->include_year, $f->pad,
                        (array) ($f->document_type_codes ?? []), $f->status])->all()],
            ],
        ];
    }
}
