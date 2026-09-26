<?php

declare(strict_types=1);

namespace App\Application\Quotes;

use App\Application\Documents\Letterhead\LetterheadResolver;
use App\Models\Quote;

/**
 * REQ-QUO-004 — the quotation PDF (INSURANCE_QUOTE), rendered on demand from the stored quote and offers
 * (no pricing is recomputed). Bilingual EN/FR labels; amounts in minor units of the offer currency.
 */
final class QuoteDocumentRenderer
{
    public function pdf(Quote $quote): string
    {
        $quote->loadMissing(['party', 'offers.carrier.party', 'offers.product']);
        $money = static fn ($minor, $cur) => number_format((int) $minor, 0, ',', ' ').' '.$cur;
        // Shared letterhead: the quoting organisation (broker tenant) or the platform; text wordmark when no artwork.
        $tenant = $quote->tenant_id ? \App\Models\Tenant::find($quote->tenant_id) : null;
        $letterhead = $tenant?->type === 'BROKER'
            ? LetterheadResolver::forDocument('BROKER', (string) $tenant->legal_name, null, null, $tenant->id, null)
            : LetterheadResolver::forDocument('PLATFORM', 'OpesInsure', null, null, null, null);
        $offers = [];
        foreach ($quote->offers->whereIn('status', ['OFFERED', 'ACCEPTED'])->sortBy('comparison_rank') as $o) {
            $offers[] = '#'.$o->comparison_rank.' '.$o->carrier?->party?->display_name.' — '.$o->product?->name.' · Prime nette / Net premium: '.$money($o->premium_minor, $o->currency)
                .' · Taxes & frais / Taxes & fees: '.$money($o->tax_minor + $o->fee_minor, $o->currency).' · Total: '.$money($o->total_minor, $o->currency)
                .' · Validité / Valid until: '.$o->valid_until?->format('Y-m-d');
        }

        // D3: canonical secure shell (QUOTE master shell); quote number and stored offers unchanged.
        return app(\App\Application\Documents\Engine\SecureShellRenderer::class)->render([
            'type_code' => 'INSURANCE_QUOTE', 'shell' => 'TPL-SHELL-QUOTE-001', 'number' => (string) ($quote->quote_number ?? $quote->id),
            'issuer_name' => $tenant?->type === 'BROKER' ? (string) $tenant->legal_name : 'OpesInsure', 'letterhead' => $letterhead,
            'title_en' => 'Quotation', 'title_fr' => 'Devis', 'label' => 'QUOTE '.$quote->line_code, 'status' => 'ISSUED',
            'values' => array_filter(['party.name' => $quote->party?->display_name, 'policy.product' => $quote->line_code, 'policy.effective_until' => $quote->expires_at?->toIso8601String()]),
            'sections' => [['heading' => 'Offres / Offers', 'paragraphs' => $offers !== [] ? $offers : ['—']],
                ['heading' => null, 'paragraphs' => ['Ce devis ne vaut pas attestation d\'assurance. / This quotation is not a certificate of insurance.']]],
            'template_ref' => 'SYSTEM quotation',
        ]);
    }
}
