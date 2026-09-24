<?php

declare(strict_types=1);

namespace App\Application\Documents\Engine;

use App\Models\Document;
use App\Models\Policy;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * "Download Policy Pack": one ZIP with each current, customer-visible issued
 * document kept as its own file (never one merged "policy PDF") plus a
 * manifest.json (number, type, status, sha256, verification code).
 */
final class PolicyPackArchiver
{
    public function __construct(private DocumentRegister $register) {}

    /** @return string path to a temporary zip file */
    public function build(Policy $policy, ?string $manifestId = null): string
    {
        $docs = Document::where('policy_id', $policy->id)->whereIn('document_origin', DocumentRegister::ISSUED_ORIGINS)->whereNotNull('document_type_code')
            ->when($manifestId, fn ($q) => $q->where('pack_manifest_id', $manifestId))
            ->orderBy('created_at')->get()
            ->filter(fn (Document $d) => DocumentAccessPolicy::customerMay($d) && in_array(DocumentEngine::effectiveStatus($d), DocumentRegister::CURRENT_STATUSES, true));

        $disk = Storage::disk((string) config('lifecycle.documents_disk', 'local'));
        $path = tempnam(sys_get_temp_dir(), 'pack').'.zip';
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot create pack archive.');
        }
        $manifest = [];
        $i = 0;
        foreach ($docs as $d) {
            if (! $disk->exists($d->storage_key)) {
                continue;
            }
            $name = sprintf('%02d-%s%s.%s', ++$i, $d->document_type_code, $d->subject_key ? '-'.preg_replace('/[^A-Za-z0-9]+/', '', $d->subject_key) : '', pathinfo($d->storage_key, PATHINFO_EXTENSION) ?: 'pdf');
            $zip->addFromString($name, (string) $disk->get($d->storage_key));
            $manifest[] = ['file' => $name, 'document_number' => $d->document_number, 'document_type_code' => $d->document_type_code, 'title' => $this->register->describe((string) $d->document_type_code)['name_en'],
                'status' => DocumentEngine::effectiveStatus($d), 'language' => $d->language, 'sha256' => $d->sha256, 'verification_code' => $d->verification_code, 'issued_at' => $d->issued_at?->toIso8601String()];
        }
        $zip->addFromString('manifest.json', (string) json_encode(['policy_number' => $policy->policy_number, 'policy_version' => $policy->version, 'documents' => $manifest], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $zip->close();

        return $path;
    }
}
