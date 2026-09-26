<?php

declare(strict_types=1);

namespace App\Application\Documents\Security;

use App\Models\Policy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * D2 mapped field rules (DetailedFieldSourceMap, MAPPED_PLATFORM_SOURCE): the values the platform already holds
 * for the policy / offer / quote / proposal / claim / transaction / payment in the issuance context. Read-only;
 * a key whose source is empty is null (the shell then omits the row: never a blank placeholder). Only new
 * issuance reads these — issued documents keep their frozen snapshot.
 *
 * @phpstan-type Ctx array{transaction?: mixed, claim?: mixed, payment?: mixed}
 */
final class MappedFieldValues
{
    /** @return array<string, mixed> */
    public static function resolve(Policy $policy, array $ctx): array
    {
        $offer = $policy->proposal?->offer;
        $quote = $offer?->quote;
        $proposal = $policy->proposal;
        $product = $offer?->product;
        $claim = $ctx['claim'] ?? null;
        $tx = $ctx['transaction'] ?? null;
        $coverages = (array) ($policy->terms_snapshot['coverage_snapshot']['coverages'] ?? $offer?->coverage_snapshot['coverages'] ?? []);
        $attr = fn ($model, string $k) => $model ? $model->getAttribute($k) : null;
        $iso = fn ($d) => $d instanceof \DateTimeInterface ? $d->format(DATE_ATOM) : ($d ?: null);

        $v = [
            'quote.number' => $attr($quote, 'quote_number'),
            'quote.valid_until' => $iso($attr($offer, 'valid_until')),
            'document.validity' => null,
            'premium.base' => $attr($offer, 'premium_minor'),
            'premium.net' => $attr($offer, 'premium_minor'),
            'premium.fees' => $attr($offer, 'fee_minor'),
            'proposal.number' => $attr($proposal, 'proposal_number'),
            'proposal.submitted_at' => $iso($attr($proposal, 'submitted_at')),
            'underwriting.required' => $attr($product, 'underwriting_mode'),
            'coverage.mandatory_flags' => array_values(array_filter(array_map(fn ($c) => is_array($c) && isset($c['mandatory'])
                ? (is_array($c['name'] ?? null) ? ($c['name']['en'] ?? reset($c['name'])) : ($c['name'] ?? $c['code'] ?? '?')).' ('.($c['mandatory'] ? 'mandatory / obligatoire' : 'optional / facultative').')' : null, $coverages))),
            'coverage.sum_insured' => null,
            'policy.term' => $policy->coverage_starts_at && $policy->coverage_ends_at ? $policy->coverage_starts_at->format('d/m/Y').' → '.$policy->coverage_ends_at->format('d/m/Y') : null,
            'policy.certificates' => $policy->certificate_number,
            'claim.loss_location' => is_scalar($attr($claim, 'loss_location')) ? $attr($claim, 'loss_location') : null,
            'claim.estimated_loss' => $attr($claim, 'estimated_loss_minor'),
            'claim.reserve_outstanding' => $attr($claim, 'current_reserve_minor'),
            'claim.submitted_at' => $iso($attr($claim, 'submitted_at')),
            'endorsement.type' => $attr($tx, 'endorsement_type'),
            'endorsement.premium_delta' => $attr($tx, 'premium_delta_minor'),
            'renewal.due_on' => null,
        ];
        try {
            if ($policy->id && Schema::hasTable('renewal_cases')) {
                $v['renewal.due_on'] = DB::table('renewal_cases')->where('policy_id', $policy->id)->orderByDesc('due_on')->value('due_on');
            }
        } catch (Throwable) {
            // optional source
        }

        return array_filter($v, fn ($x) => $x !== null && $x !== '' && $x !== []);
    }
}
