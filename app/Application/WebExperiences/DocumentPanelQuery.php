<?php

declare(strict_types=1);

namespace App\Application\WebExperiences;

use App\Application\Documents\Engine\DocumentAccessPolicy;
use App\Models\{Claim, Document, Policy, User};
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
        $query = Document::query()->where('tenant_id', $record->getAttribute('tenant_id'));
        if ($record instanceof Policy) {
            $query->where('policy_id', $record->getKey());
        } elseif ($record instanceof Claim) {
            $query->where('claim_id', $record->getKey());
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
            ];
        }

        if ($record instanceof Policy) {
            foreach (DB::table('policy_certificates')->where('policy_id', $record->getKey())->orderByDesc('issued_at')->get() as $c) {
                $rows[] = [
                    'id' => $c->id, 'title' => __('web_experience.documents.certificate'), 'number' => $c->serial_number, 'version' => null,
                    'status' => $c->status, 'verification' => $c->status === 'VALID' ? 'VERIFIED' : $c->status, 'issuer' => 'CARRIER',
                    'issued_at' => substr((string) $c->issued_at, 0, 10), 'expires_at' => optional($record->coverage_ends_at)->toDateString(),
                    'replaces' => null, 'replaced_by' => null, 'verify_url' => null,
                ];
            }
        }

        return ['rows' => $rows, 'withheld' => $withheld];
    }
}
