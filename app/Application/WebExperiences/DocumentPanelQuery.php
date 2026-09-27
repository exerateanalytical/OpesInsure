<?php

declare(strict_types=1);

namespace App\Application\WebExperiences;

use App\Application\Documents\Engine\DocumentAccessPolicy;
use App\Domain\Tenancy\TenantContext;
use App\Models\{Claim, Document, Partner, Party, Policy, Proposal, TenantCustomer, User};
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * SSR §27 document viewer rows over the canonical documents register
 * (document engine) + issued policy certificates. Sensitive security levels
 * are filtered with the existing DocumentAccessPolicy::staffMay(); the panel
 * shows how many rows were withheld instead of leaking them.
 */
final class DocumentPanelQuery
{
    /** @return array{rows: list<array<string, mixed>>, withheld: int} */
    public function for(Model $record, User $viewer): array
    {
        $tenantId = $record->getAttribute('tenant_id') ?: app(TenantContext::class)->id();
        $query = Document::query()->where('tenant_id', $tenantId);
        if ($record instanceof Policy) {
            $query->where('policy_id', $record->getKey());
        } elseif ($record instanceof Claim) {
            $query->where('claim_id', $record->getKey());
        } elseif ($record instanceof Proposal) {
            $query->whereIn('policy_id', Policy::where(['tenant_id' => $tenantId, 'proposal_id' => $record->getKey()])->select('id'));
        } elseif ($record instanceof Party || $record instanceof TenantCustomer || $record instanceof Partner) {
            $query->where('party_id', $record instanceof Party ? $record->getKey() : $record->getAttribute('party_id'));
        } elseif ($record instanceof Document) {
            $query->whereKey($record->getKey());
        } else {
            return ['rows' => [], 'withheld' => 0];
        }

        $rows = [];
        $withheld = 0;
        foreach ($query->orderByDesc('created_at')->limit(100)->get() as $d) {
            if (! DocumentAccessPolicy::staffMay($viewer, $d)) {
                $withheld++;

                continue;
            }
            $rows[] = [
                'id' => $d->getKey(),
                'title' => $d->title ?: ($d->document_type_code ?: $d->category),
                'number' => $d->document_number,
                'version' => $d->template_version,
                'status' => $d->status,
                'verification' => $d->verification_status,
                'issuer' => $d->issuer_type,
                'issued_at' => optional($d->issued_at)->toDateString(),
                'expires_at' => optional($d->valid_until)->toDateString(),
                'replaces' => $d->supersedes_document_id,
                'replaced_by' => $d->superseded_by_document_id,
                'verify_url' => $d->verification_code ? route('public.verify', ['code' => $d->verification_code]) : null,
                'download_url' => self::downloadUrl($d),
            ];
        }

        if ($record instanceof Policy) {
            foreach (DB::table('policy_certificates')->where('policy_id', $record->getKey())->orderByDesc('issued_at')->get() as $c) {
                $rows[] = [
                    'id' => $c->id, 'title' => __('web_experience.documents.certificate'), 'number' => $c->serial_number, 'version' => null,
                    'status' => $c->status, 'verification' => $c->status === 'VALID' ? 'VERIFIED' : $c->status, 'issuer' => 'CARRIER',
                    'issued_at' => substr((string) $c->issued_at, 0, 10), 'expires_at' => optional($record->coverage_ends_at)->toDateString(),
                    'replaces' => null, 'replaced_by' => null, 'verify_url' => null, 'download_url' => null,
                ];
            }
        }

        return ['rows' => $rows, 'withheld' => $withheld];
    }

    /**
     * Short-lived signed download link for a stored, clean document; null otherwise. Policy documents use the
     * existing PolicyDocumentService / mobile.policy-documents.download route; claim evidence uses the bound
     * SignedUrlAdapter (mobile.documents.download, the same link DocumentController::access mints). Both target
     * routes log every download in document_access_log. Callers only reach this for rows that already passed
     * DocumentAccessPolicy::staffMay().
     */
    public static function downloadUrl(Document $d): ?string
    {
        if (! $d->storage_key || $d->scan_status !== 'CLEAN') {
            return null;
        }
        if ($d->policy_id) {
            return app(\App\Application\Policies\PolicyDocumentService::class)->downloadUrl($d);
        }
        if ($d->claim_id) {
            return app(\App\Application\Documents\Adapters\SignedUrlAdapter::class)->sign($d, self::CLAIM_EVIDENCE_TTL_SECONDS)->url;
        }

        return null;
    }

    /** Same lifetime as the API's DocumentController::access link. */
    private const CLAIM_EVIDENCE_TTL_SECONDS = 300;
}
