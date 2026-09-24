<?php

declare(strict_types=1);

namespace App\Application\Documents\Engine;

use App\Models\Document;
use App\Models\DocumentPackManifest;
use App\Models\Policy;
use Illuminate\Support\Facades\URL;

/**
 * Contract history + grouped documents for one policy (mobile API contract of
 * GET /api/v1/mobile/policies/{id}/documents?group=&stage=).
 *  - contract_history: renewal chain (previous_policy_id) and every event
 *    manifest: ORIGINAL → AVENANT 001 → 002 → RENEWAL, in order;
 *  - groups: POLICY_PACK, CERTIFICATES, SERVICING, CLAIMS, FINANCIAL, each
 *    with `documents` (current) and `history` (superseded/replaced/revoked/
 *    expired/cancelled) — never mixed;
 *  - packs: manifest items with states (GENERATED, CARRIER_PROVIDED,
 *    AWAITING_CARRIER_DOCUMENT …);
 *  - evidence: customer / third-party uploads, kept apart, never insurer-issued.
 * Security levels: only customer-visible levels are returned.
 */
final class PolicyDocumentsQuery
{
    public function __construct(private DocumentRegister $register) {}

    /** @return array<string, mixed> */
    public function forCustomer(Policy $policy, ?string $group = null, ?string $stage = null): array
    {
        $chainIds = $this->chain($policy);
        $docs = Document::whereIn('policy_id', $chainIds)->orderBy('created_at')->get()->filter(fn (Document $d) => DocumentAccessPolicy::customerMay($d));
        $numbers = $docs->pluck('document_number', 'id');

        $issued = $docs->filter(fn (Document $d) => in_array($d->document_origin, DocumentRegister::ISSUED_ORIGINS, true) && $d->document_type_code);
        $evidence = $docs->reject(fn (Document $d) => in_array($d->document_origin, DocumentRegister::ISSUED_ORIGINS, true) && $d->document_type_code);

        $groups = [];
        foreach (DocumentRegister::DISPLAY_GROUPS as $g) {
            if ($group && $group !== $g) {
                continue;
            }
            $inGroup = $issued->filter(fn (Document $d) => $this->register->describe((string) $d->document_type_code)['display_group'] === $g)
                ->filter(fn (Document $d) => ! $stage || $d->document_stage === $stage);
            $current = $inGroup->filter(fn (Document $d) => $d->policy_id === $policy->id && in_array(DocumentEngine::effectiveStatus($d), DocumentRegister::CURRENT_STATUSES, true));
            $history = $inGroup->diff($current);
            $groups[] = ['group' => $g, 'documents' => $current->map(fn ($d) => $this->doc($d, $numbers->all()))->values()->all(), 'history' => $history->sortByDesc('created_at')->map(fn ($d) => $this->doc($d, $numbers->all()))->values()->all()];
        }

        $manifests = DocumentPackManifest::whereIn('policy_id', $chainIds)->orderBy('generated_at')->get();
        $policies = Policy::whereIn('id', $chainIds)->get()->keyBy('id');

        return [
            'policy' => ['id' => $policy->id, 'policy_number' => $policy->policy_number, 'version' => (int) $policy->version, 'status' => $policy->status],
            'contract_history' => $manifests->map(fn (DocumentPackManifest $m) => [
                'manifest_id' => $m->id, 'policy_id' => $m->policy_id, 'policy_number' => $policies[$m->policy_id]->policy_number ?? null,
                'kind' => match ($m->trigger) { 'POLICY_ISSUED' => 'ORIGINAL', 'ENDORSEMENT_ISSUED' => 'ENDORSEMENT', 'RENEWAL_ISSUED' => 'RENEWAL', 'CANCELLATION_ISSUED' => 'CANCELLATION', 'REINSTATEMENT_ISSUED' => 'REINSTATEMENT', 'PAYMENT_RECONCILED' => 'PAYMENT', default => 'CLAIM' },
                'label' => $m->event_label, 'sequence' => $m->sequence, 'policy_version' => $m->policy_version, 'generated_at' => $m->generated_at?->toIso8601String(),
            ])->values()->all(),
            'groups' => $groups,
            'packs' => $manifests->where('policy_id', $policy->id)->map(fn (DocumentPackManifest $m) => [
                'manifest_id' => $m->id, 'pack_code' => $m->pack_code, 'trigger' => $m->trigger, 'label' => $m->event_label, 'generated_at' => $m->generated_at?->toIso8601String(),
                'items' => array_map(fn ($i) => array_intersect_key($i, array_flip(['document_type_code', 'document_type_id', 'title_en', 'title_fr', 'display_group', 'required_level', 'subject_label', 'state', 'document_id'])), $m->items ?? []),
            ])->values()->all(),
            'evidence' => $evidence->where('policy_id', $policy->id)->map(fn (Document $d) => [
                'id' => $d->id, 'category' => $d->category, 'origin' => $d->document_origin ?? 'CUSTOMER', 'issued_by_insurer' => false,
                'uploaded_at' => $d->created_at?->toIso8601String(), 'mime_type' => $d->mime_type,
            ])->values()->all(),
            'pack_download_url' => $issued->where('policy_id', $policy->id)->isNotEmpty() ? self::packUrl($policy) : null,
        ];
    }

    public static function packUrl(Policy $policy, ?string $manifestId = null): string
    {
        return URL::temporarySignedRoute('mobile.policy-pack.download', now()->addMinutes((int) config('lifecycle.download_ttl_minutes', 30)), array_filter(['policy' => $policy->id, 'manifest' => $manifestId]));
    }

    /** Renewal chain, oldest first. @return array<int, string> */
    public function chain(Policy $policy): array
    {
        $ids = [$policy->id];
        $cursor = $policy;
        while ($cursor->previous_policy_id && ! in_array($cursor->previous_policy_id, $ids, true) && count($ids) < 50) {
            $cursor = Policy::find($cursor->previous_policy_id);
            if (! $cursor || $cursor->party_id !== $policy->party_id) {
                break;
            }
            array_unshift($ids, $cursor->id);
        }

        return $ids;
    }

    /** @param array<string, ?string> $numbers @return array<string, mixed> */
    private function doc(Document $d, array $numbers): array
    {
        $t = $this->register->describe((string) $d->document_type_code);
        $status = DocumentEngine::effectiveStatus($d);

        return [
            'id' => $d->id, 'document_type_code' => $d->document_type_code, 'document_type_id' => $d->document_type_id,
            'title' => $t['name_en'], 'title_fr' => $t['name_fr'], 'group' => $t['display_group'], 'stage' => $d->document_stage,
            'status' => $status, 'is_current' => in_array($status, DocumentRegister::CURRENT_STATUSES, true), 'status_reason' => $d->status_reason,
            'language' => $d->language, 'issued_at' => ($d->issued_at ?? $d->created_at)?->toIso8601String(),
            'document_number' => $d->document_number, 'verification_code' => $d->verification_code,
            'verification_url' => $d->verification_code ? rtrim((string) config('lifecycle.verify_url'), '/').'?code='.$d->verification_code : null,
            'origin' => $d->document_origin, 'issuer_type' => $d->issuer_type, 'is_carrier_original' => (bool) $d->is_carrier_original,
            'subject_label' => $d->subject_label, 'policy_id' => $d->policy_id, 'policy_version' => $d->policy_version,
            'replaced_by' => $d->superseded_by_document_id ? ($numbers[$d->superseded_by_document_id] ?? $d->superseded_by_document_id) : null,
            'download_url' => URL::temporarySignedRoute('mobile.policy-documents.download', now()->addMinutes((int) config('lifecycle.download_ttl_minutes', 30)), ['document' => $d->id]),
        ];
    }
}
