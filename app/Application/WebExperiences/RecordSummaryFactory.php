<?php

declare(strict_types=1);

namespace App\Application\WebExperiences;

use App\Models\{Claim, Document, Partner, Party, Policy, Proposal, Quote, TenantCustomer};
use Illuminate\Database\Eloquent\Model;

/** Builds RecordSummary for the canonical records (policy, claim, quote); falls back to generic columns. */
final class RecordSummaryFactory
{
    public function for(Model $record): RecordSummary
    {
        return match (true) {
            $record instanceof Policy => $this->policy($record),
            $record instanceof Claim => $this->claim($record),
            $record instanceof Quote => $this->quote($record),
            $record instanceof Proposal => $this->proposal($record),
            $record instanceof Document => $this->document($record),
            $record instanceof Party => $this->party($record),
            $record instanceof TenantCustomer => $this->customer($record),
            $record instanceof Partner => $this->partner($record),
            default => $this->generic($record),
        };
    }

    private function policy(Policy $p): RecordSummary
    {
        $status = (string) $p->status;

        return new RecordSummary('policy', (string) ($p->policy_number ?: $p->getKey()), (string) ($p->party?->display_name ?? __('web_experience.unknown')), $status, RecordSummary::toneFor($status), [
            __('web_experience.meta.carrier') => $p->carrier?->cima_code,
            __('web_experience.meta.premium') => Money::format((int) $p->premium_minor, (string) $p->currency),
            __('web_experience.meta.cover') => optional($p->coverage_starts_at)->toDateString().' → '.optional($p->coverage_ends_at)->toDateString(),
            __('web_experience.meta.version') => $p->version,
        ], ['overview', 'timeline', 'documents', 'financial']);
    }

    private function claim(Claim $c): RecordSummary
    {
        $status = (string) $c->status;

        return new RecordSummary('claim', (string) ($c->claim_number ?: $c->getKey()), (string) ($c->policy?->policy_number ?? __('web_experience.unknown')), $status, RecordSummary::toneFor($status), [
            __('web_experience.meta.priority') => $c->priority,
            __('web_experience.meta.reserve') => Money::format((int) $c->current_reserve_minor, (string) ($c->currency ?: 'XAF')),
            __('web_experience.meta.assignee') => $c->assignee?->full_name,
            __('web_experience.meta.loss_at') => optional($c->loss_occurred_at)->toDateTimeString(),
        ], ['overview', 'timeline', 'documents', 'financial', 'authority']);
    }

    private function quote(Quote $q): RecordSummary
    {
        $status = (string) $q->status;

        return new RecordSummary('quote', (string) ($q->quote_number ?: $q->getKey()), (string) ($q->party?->display_name ?? __('web_experience.unknown')), $status, RecordSummary::toneFor($status), [
            __('web_experience.meta.line') => $q->line_code,
            __('web_experience.meta.created') => optional($q->created_at)->toDateTimeString(),
        ], ['overview', 'timeline']);
    }

    private function generic(Model $m): RecordSummary
    {
        $status = (string) ($m->getAttribute('status') ?? 'UNKNOWN');

        return new RecordSummary(class_basename($m), (string) $m->getKey(), class_basename($m), $status, RecordSummary::toneFor($status));
    }

    private function proposal(Proposal $p): RecordSummary
    {
        $status = (string) $p->status;
        $total = data_get($p->terms_snapshot, 'total_minor');

        return new RecordSummary('proposal', (string) ($p->proposal_number ?: $p->getKey()), (string) ($p->party?->display_name ?? __('web_experience.unknown')), $status, RecordSummary::toneFor($status), [
            __('web_experience.meta.product') => $p->offer?->product?->name,
            __('web_experience.meta.carrier') => $p->offer?->carrier?->cima_code,
            __('web_experience.meta.total') => $total !== null ? Money::format((int) $total, (string) (data_get($p->terms_snapshot, 'currency') ?: 'XAF')) : null,
            __('web_experience.meta.submitted') => optional($p->submitted_at)->toDateTimeString(),
        ], ['overview', 'timeline', 'documents', 'financial', 'related']);
    }

    private function document(Document $d): RecordSummary
    {
        $status = (string) $d->status;

        return new RecordSummary('document', (string) ($d->document_number ?: $d->verification_code ?: $d->getKey()), (string) ($d->title ?: $d->document_type_code ?: $d->category), $status, RecordSummary::toneFor($status), [
            __('web_experience.meta.type') => $d->document_type_code,
            __('web_experience.meta.security') => $d->security_level,
            __('web_experience.meta.issued') => optional($d->issued_at)->toDateTimeString(),
            __('web_experience.meta.valid_until') => optional($d->valid_until)->toDateString(),
        ], ['overview', 'timeline', 'documents', 'related']);
    }

    private function party(Party $p): RecordSummary
    {
        $status = (string) $p->status;

        return new RecordSummary('party', (string) $p->display_name, (string) $p->type, $status, RecordSummary::toneFor($status), [
            __('web_experience.meta.created') => optional($p->created_at)->toDateString(),
        ], ['overview', 'timeline', 'documents', 'related']);
    }

    private function customer(TenantCustomer $c): RecordSummary
    {
        $status = (string) $c->status;

        return new RecordSummary('customer', (string) $c->customer_number, (string) ($c->party?->display_name ?? __('web_experience.unknown')), $status, RecordSummary::toneFor($status), [
            __('web_experience.meta.organization') => $c->tenant?->legal_name,
            __('web_experience.meta.created') => optional($c->created_at)->toDateString(),
        ], ['overview', 'timeline', 'documents', 'related']);
    }

    private function partner(Partner $p): RecordSummary
    {
        $status = (string) $p->status;

        return new RecordSummary('partner', (string) ($p->party?->display_name ?? $p->legal_name ?? $p->getKey()), (string) $p->type, $status, RecordSummary::toneFor($status), [
            __('web_experience.meta.licence') => $p->licence_number,
            __('web_experience.meta.licence_expires') => optional($p->licence_expires_on)->toDateString(),
            __('web_experience.meta.organization') => $p->tenant?->legal_name,
        ], ['overview', 'timeline', 'documents', 'related']);
    }
}
