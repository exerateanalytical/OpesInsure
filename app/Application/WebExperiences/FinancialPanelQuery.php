<?php

declare(strict_types=1);

namespace App\Application\WebExperiences;

use App\Models\{Claim, Policy};
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * SSR §28 financial panel rows: amount, currency, status, source, payer,
 * payee, reconciliation and journal reference. Reads the existing
 * payment_intents / claim_payments / reconciliation_items / journals —
 * no second ledger.
 */
final class FinancialPanelQuery
{
    /** @return list<array{label:string, amount:string, status:?string, source:?string, payer:?string, payee:?string, reference:?string, reconciliation:?string, journal:?string}> */
    public function for(Model $record): array
    {
        if ($record instanceof Policy) {
            return DB::table('payment_intents')->where('tenant_id', $record->tenant_id)
                ->where(fn ($q) => $q->where('proposal_id', $record->proposal_id)->when($record->payment_intent_id, fn ($w) => $w->orWhere('id', $record->payment_intent_id)))
                ->orderBy('created_at')->get()->map(fn ($p) => [
                    'label' => __('web_experience.financial.premium_payment'),
                    'amount' => Money::format((int) $p->amount_minor, $p->currency),
                    'status' => $p->status,
                    'source' => $p->provider,
                    // payer phone is personal data: masked (never shown in full on a shared screen)
                    'payer' => $p->payer_phone_e164 ? substr((string) $p->payer_phone_e164, 0, 7).'•••' : null,
                    'payee' => $record->carrier?->cima_code,
                    'reference' => $p->provider_reference,
                    'reconciliation' => DB::table('reconciliation_items')->where('matched_type', 'payment_intent')->where('matched_id', $p->id)->value('status'),
                    'journal' => DB::table('journals')->where('reference_id', $p->id)->value('id'),
                ])->all();
        }

        if ($record instanceof Claim) {
            return $record->payments()->orderBy('created_at')->get()->map(fn ($p) => [
                'label' => __('web_experience.financial.claim_payment'),
                'amount' => Money::format((int) $p->amount_minor, $p->currency),
                'status' => $p->status,
                'source' => 'CLAIM_DECISION',
                'payer' => $record->policy?->carrier?->cima_code,
                'payee' => DB::table('parties')->where('id', $p->payee_party_id)->value('display_name'),
                'reference' => $p->external_reference,
                'reconciliation' => null,
                'journal' => DB::table('journals')->where('reference_id', $p->id)->value('id'),
            ])->all();
        }

        return [];
    }
}
