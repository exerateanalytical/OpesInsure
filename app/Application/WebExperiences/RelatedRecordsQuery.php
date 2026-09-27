<?php

declare(strict_types=1);

namespace App\Application\WebExperiences;

use App\Domain\Tenancy\TenantContext;
use App\Models\{Claim, ClaimPayment, CommissionAccrual, CustomerAttribution, Document, Partner, PartnerLicence, PartnerStatement, Party, Policy, PolicyTransaction, Proposal, Quote, QuoteOffer, RenewalCase, TenantCustomer, UnderwritingCase};
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * REQ-UI-002 "Related records" tab rows for the core detail pages (policy,
 * claim, quote, proposal, document, party, customer, partner). Read-only
 * projection of existing relations, tenant-scoped to the record's tenant (or
 * the current TenantContext for platform-wide records such as parties).
 * Links resolve through the CURRENT panel's registered resource for the
 * related model, so a portal never links to a screen it does not expose.
 *
 * Group shape: ['key' => string, 'heading' => string, 'rows' => list<Row>]
 * Row shape:   ['label' => string, 'detail' => ?string, 'status' => ?string, 'url' => ?string]
 */
final class RelatedRecordsQuery
{
    private const LIMIT = 25;

    /** @return list<array{key:string, heading:string, rows:list<array<string, mixed>>}> */
    public function for(Model $record): array
    {
        $groups = match (true) {
            $record instanceof Policy => $this->policy($record),
            $record instanceof Claim => $this->claim($record),
            $record instanceof Quote => $this->quote($record),
            $record instanceof Proposal => $this->proposal($record),
            $record instanceof Document => $this->document($record),
            $record instanceof Party => $this->party($record, $this->tenant($record)),
            $record instanceof TenantCustomer => $this->party($record->party, (string) $record->tenant_id, false),
            $record instanceof Partner => $this->partner($record),
            default => [],
        };

        return array_values(array_filter($groups, fn (array $g) => $g['rows'] !== []));
    }

    private function policy(Policy $p): array
    {
        return [
            $this->group('customer', [$this->partyRow($p->party)]),
            $this->group('proposal', $p->proposal ? [$this->row($p->proposal, (string) ($p->proposal->proposal_number ?: $p->proposal->getKey()), null)] : []),
            $this->group('claims', Claim::where(['tenant_id' => $p->tenant_id, 'policy_id' => $p->id])->latest()->limit(self::LIMIT)->get()
                ->map(fn (Claim $c) => $this->row($c, (string) ($c->claim_number ?: $c->id), optional($c->loss_occurred_at)->toDateString()))->all()),
            $this->group('transactions', PolicyTransaction::where(['tenant_id' => $p->tenant_id, 'policy_id' => $p->id])->latest()->limit(self::LIMIT)->get()
                ->map(fn (PolicyTransaction $t) => $this->row($t, (string) ($t->transaction_number ?: $t->type), $t->type.' · '.optional($t->effective_at)->toDateString()))->all()),
            $this->group('renewals', RenewalCase::where('policy_id', $p->id)->latest()->limit(self::LIMIT)->get()
                ->map(fn (RenewalCase $r) => $this->row($r, (string) ($r->getAttribute('renewal_number') ?: $r->id), null))->all()),
            $this->group('commissions', CommissionAccrual::where('policy_id', $p->id)->latest()->limit(self::LIMIT)->get()
                ->map(fn (CommissionAccrual $a) => $this->row($a, Money::format((int) $a->amount_minor, (string) $a->currency), Partner::find($a->partner_id)?->party?->display_name))->all()),
        ];
    }

    private function claim(Claim $c): array
    {
        return [
            $this->group('policy', $c->policy ? [$this->row($c->policy, (string) $c->policy->policy_number, $c->policy->party?->display_name)] : []),
            $this->group('claimant', $c->claimant_party_id ? [$this->partyRow(Party::find($c->claimant_party_id))] : []),
            $this->group('claim_payments', ClaimPayment::where('claim_id', $c->id)->latest()->limit(self::LIMIT)->get()
                ->map(fn (ClaimPayment $p) => $this->row($p, Money::format((int) $p->amount_minor, (string) $p->currency), optional($p->created_at)->toDateString()))->all()),
        ];
    }

    private function quote(Quote $q): array
    {
        $offers = QuoteOffer::where('quote_id', $q->id)->orderBy('comparison_rank')->limit(self::LIMIT)->get();

        return [
            $this->group('customer', [$this->partyRow($q->party)]),
            $this->group('offers', $offers->map(fn (QuoteOffer $o) => [
                'label' => (string) ($o->product?->name ?? $o->carrier?->cima_code ?? $o->id),
                'detail' => trim(($o->carrier?->cima_code ?? '').' · '.Money::format((int) $o->total_minor, (string) $o->currency), ' ·'),
                'status' => $o->status, 'url' => null,
            ])->all()),
            $this->group('proposals', Proposal::where('tenant_id', $q->tenant_id)->whereIn('quote_offer_id', $offers->pluck('id'))->limit(self::LIMIT)->get()
                ->map(fn (Proposal $p) => $this->row($p, (string) ($p->proposal_number ?: $p->id), optional($p->submitted_at)->toDateString()))->all()),
        ];
    }

    private function proposal(Proposal $p): array
    {
        $quote = $p->offer?->quote;

        return [
            $this->group('customer', [$this->partyRow($p->party)]),
            $this->group('quote', $quote && $quote->tenant_id === $p->tenant_id ? [$this->row($quote, (string) ($quote->quote_number ?: $quote->id), $quote->line_code)] : []),
            $this->group('underwriting', UnderwritingCase::where(['tenant_id' => $p->tenant_id, 'proposal_id' => $p->id])->limit(self::LIMIT)->get()
                ->map(fn (UnderwritingCase $u) => $this->row($u, (string) $u->id, $u->priority))->all()),
            $this->group('policies', Policy::where(['tenant_id' => $p->tenant_id, 'proposal_id' => $p->id])->limit(self::LIMIT)->get()
                ->map(fn (Policy $x) => $this->row($x, (string) $x->policy_number, optional($x->coverage_ends_at)->toDateString()))->all()),
        ];
    }

    private function document(Document $d): array
    {
        $versions = Document::where('tenant_id', $d->tenant_id)->whereKey(array_filter([$d->supersedes_document_id, $d->superseded_by_document_id]))->get();

        return [
            $this->group('policy', $d->policy && $d->policy->tenant_id === $d->tenant_id ? [$this->row($d->policy, (string) $d->policy->policy_number, $d->policy->party?->display_name)] : []),
            $this->group('claim', $d->claim_id ? Claim::where(['tenant_id' => $d->tenant_id, 'id' => $d->claim_id])->get()->map(fn (Claim $c) => $this->row($c, (string) $c->claim_number, null))->all() : []),
            $this->group('customer', $d->party ? [$this->partyRow($d->party)] : []),
            $this->group('versions', $versions->map(fn (Document $v) => $this->row($v, (string) ($v->document_number ?? $v->verification_code ?? $v->id),
                $v->id === $d->supersedes_document_id ? __('web_experience.related.replaces') : __('web_experience.related.replaced_by')))->all()),
        ];
    }

    private function party(?Party $party, ?string $tenantId, bool $withCustomers = true): array
    {
        if (! $party) {
            return [];
        }
        $scoped = fn (Builder $q) => $tenantId ? $q->where('tenant_id', $tenantId) : $q->whereRaw('1 = 0');

        return [
            $withCustomers ? $this->group('customer_relationships', $scoped(TenantCustomer::where('party_id', $party->id))->limit(self::LIMIT)->get()
                ->map(fn (TenantCustomer $c) => $this->row($c, (string) $c->customer_number, $c->tenant?->legal_name))->all()) : $this->group('identity', [$this->partyRow($party)]),
            $this->group('policies', $scoped(Policy::where('party_id', $party->id))->latest()->limit(self::LIMIT)->get()
                ->map(fn (Policy $p) => $this->row($p, (string) $p->policy_number, optional($p->coverage_ends_at)->toDateString()))->all()),
            $this->group('quotes', $scoped(Quote::where('party_id', $party->id))->latest()->limit(self::LIMIT)->get()
                ->map(fn (Quote $q) => $this->row($q, (string) ($q->quote_number ?: $q->id), $q->line_code))->all()),
            $this->group('proposals', $scoped(Proposal::where('party_id', $party->id))->latest()->limit(self::LIMIT)->get()
                ->map(fn (Proposal $p) => $this->row($p, (string) ($p->proposal_number ?: $p->id), null))->all()),
            $this->group('claims', $scoped(Claim::where('claimant_party_id', $party->id))->latest()->limit(self::LIMIT)->get()
                ->map(fn (Claim $c) => $this->row($c, (string) $c->claim_number, optional($c->loss_occurred_at)->toDateString()))->all()),
            $this->group('attributions', CustomerAttribution::where('party_id', $party->id)->whereIn('partner_id', $scoped(Partner::query())->select('id'))->limit(self::LIMIT)->get()
                ->map(fn (CustomerAttribution $a) => $this->row($a, (string) ($a->partner?->party?->display_name ?? $a->partner_id), $a->origin_type))->all()),
        ];
    }

    private function partner(Partner $p): array
    {
        $tenantId = $p->tenant_id ?: app(TenantContext::class)->id();

        return [
            $this->group('identity', [$this->partyRow($p->party)]),
            $this->group('licences', PartnerLicence::where('partner_id', $p->id)->latest()->limit(self::LIMIT)->get()
                ->map(fn (PartnerLicence $l) => $this->row($l, (string) $l->licence_number, trim($l->authority.' · '.optional($l->expires_on)->toDateString(), ' ·')))->all()),
            $this->group('attributions', CustomerAttribution::where('partner_id', $p->id)->latest()->limit(self::LIMIT)->get()
                ->map(fn (CustomerAttribution $a) => $this->row($a, (string) ($a->party?->display_name ?? $a->party_id), $a->origin_type))->all()),
            $this->group('quotes', $tenantId ? Quote::where(['tenant_id' => $tenantId, 'partner_id' => $p->id])->latest()->limit(self::LIMIT)->get()
                ->map(fn (Quote $q) => $this->row($q, (string) ($q->quote_number ?: $q->id), $q->party?->display_name))->all() : []),
            $this->group('commissions', CommissionAccrual::where('partner_id', $p->id)->latest()->limit(self::LIMIT)->get()
                ->map(fn (CommissionAccrual $a) => $this->row($a, Money::format((int) $a->amount_minor, (string) $a->currency), optional($a->vests_at)->toDateString()))->all()),
            $this->group('statements', PartnerStatement::where('partner_id', $p->id)->latest()->limit(self::LIMIT)->get()
                ->map(fn (PartnerStatement $s) => $this->row($s, (string) $s->statement_number, optional($s->period_start)->toDateString().' → '.optional($s->period_end)->toDateString()))->all()),
        ];
    }

    private function tenant(Model $record): ?string
    {
        return $record->getAttribute('tenant_id') ?: app(TenantContext::class)->id();
    }

    private function partyRow(?Party $party): ?array
    {
        return $party ? $this->row($party, (string) $party->display_name, $party->type) : null;
    }

    /** @return array{label:string, detail:?string, status:?string, url:?string} */
    private function row(Model $m, string $label, ?string $detail): array
    {
        return ['label' => $label, 'detail' => $detail, 'status' => $m->getAttribute('status'), 'url' => self::viewUrl($m)];
    }

    private function group(string $key, array $rows): array
    {
        return ['key' => $key, 'heading' => __('web_experience.related.groups.'.$key), 'rows' => array_values(array_filter($rows))];
    }

    /** View URL of the related record in the current panel, or null when that panel does not expose it. */
    public static function viewUrl(Model $m): ?string
    {
        try {
            $resource = Filament::getCurrentPanel()?->getModelResource($m);
            if (! $resource || ! $resource::hasPage('view')) {
                return null;
            }

            return $resource::getUrl('view', ['record' => $m]);
        } catch (\Throwable) {
            return null;
        }
    }
}
