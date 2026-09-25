<?php

declare(strict_types=1);

namespace App\Application\Quotes;

use App\Models\Quote;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * REQ-QUO-004 — the quotation PDF (INSURANCE_QUOTE), rendered on demand from the stored quote and offers
 * (no pricing is recomputed). Bilingual EN/FR labels; amounts in minor units of the offer currency.
 */
final class QuoteDocumentRenderer
{
    public function pdf(Quote $quote): string
    {
        $quote->loadMissing(['party', 'offers.carrier.party', 'offers.product']);
        $e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $money = static fn ($minor, $cur) => number_format((int) $minor, 0, ',', ' ').' '.$cur;
        $rows = '';
        foreach ($quote->offers->whereIn('status', ['OFFERED', 'ACCEPTED'])->sortBy('comparison_rank') as $o) {
            $rows .= '<tr><td>'.$e($o->comparison_rank).'</td><td>'.$e($o->carrier?->party?->display_name).'</td><td>'.$e($o->product?->name).'</td><td class="n">'
                .$e($money($o->premium_minor, $o->currency)).'</td><td class="n">'.$e($money($o->tax_minor + $o->fee_minor, $o->currency)).'</td><td class="n"><b>'
                .$e($money($o->total_minor, $o->currency)).'</b></td><td>'.$e($o->valid_until?->format('Y-m-d')).'</td></tr>';
        }
        $html = '<html><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:11px}table{width:100%;border-collapse:collapse}td,th{border:1px solid #999;padding:4px}.n{text-align:right}</style></head><body>'.\App\Application\Documents\DemoDocumentMark::html()
            .'<h2>Devis / Quotation '.$e($quote->quote_number ?? $quote->id).'</h2>'
            .'<p>Client / Customer: '.$e($quote->party?->display_name).'<br>Branche / Line: '.$e($quote->line_code)
            .'<br>Valide jusqu&#39;au / Valid until: '.$e($quote->expires_at?->format('Y-m-d H:i T')).'</p>'
            .'<table><tr><th>#</th><th>Assureur / Insurer</th><th>Produit / Product</th><th>Prime nette / Net premium</th><th>Taxes &amp; frais / Taxes &amp; fees</th><th>Total</th><th>Validité / Valid until</th></tr>'
            .$rows.'</table><p>Ce devis ne vaut pas attestation d&#39;assurance. / This quotation is not a certificate of insurance.</p></body></html>';

        return Pdf::loadHTML($html)->output();
    }
}
