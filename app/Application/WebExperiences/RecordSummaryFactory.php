<?php

declare(strict_types=1);

namespace App\Application\WebExperiences;

use App\Models\{Claim, Policy, Quote};
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

        return new RecordSummary('quote', (string) $q->getKey(), (string) ($q->party?->display_name ?? __('web_experience.unknown')), $status, RecordSummary::toneFor($status), [
            __('web_experience.meta.line') => $q->line_code,
            __('web_experience.meta.created') => optional($q->created_at)->toDateTimeString(),
        ], ['overview', 'timeline']);
    }

    private function generic(Model $m): RecordSummary
    {
        $status = (string) ($m->getAttribute('status') ?? 'UNKNOWN');

        return new RecordSummary(class_basename($m), (string) $m->getKey(), class_basename($m), $status, RecordSummary::toneFor($status));
    }
}
