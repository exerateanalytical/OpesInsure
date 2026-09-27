<?php

declare(strict_types=1);

namespace App\Filament\Shared\Components;

use App\Application\WebExperiences\{AuthorityAssessor, DocumentPanelQuery, FailureState, FinancialPanelQuery, RecordSummaryFactory, TimelineQuery};
use App\Domain\Tenancy\TenantContext;
use Closure;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\View;
use Illuminate\Database\Eloquent\Model;

/**
 * REQ-UI-002 reusable record shell for any panel (admin, insurer, broker).
 * Each factory returns a Filament schema component backed by a shared Blade
 * view in resources/views/filament/shared and a WebExperiences query:
 *
 *   detailHeader()      SSR §23 identifier, status, key metadata
 *   failureStates()     SSR §30 explicit known-failure banners
 *   timeline()          SSR §26 audit-log timeline
 *   documentViewer()    SSR §27 documents (security-level filtered)
 *   financialPanel()    SSR §28 payments / reconciliation / journal
 *   authorityWidget()   SSR §29 within-authority / referral
 *   relatedRecords()    REQ-UI-002 related records (RelatedRecordsQuery)
 *   detailTabs()        header + failures + tabs of the above
 *
 * Core-record detail pages (policy, claim, quote, proposal, document, party,
 * customer, partner) use detailTabs() with an Overview schema, the Related
 * tab and `without` to drop tabs that do not apply, e.g.
 *   RecordShell::detailTabs('proposal', [Section::make(...)], related: true, without: ['documents'])
 * Overview sections should be built with CoreRecordOverview (same folder).
 * Configuration / reference-data resources use RecordInfolist instead.
 */
final class RecordShell
{
    public static function detailHeader(): View
    {
        return View::make('filament.shared.detail-header')
            ->viewData(fn (?Model $record) => ['summary' => $record ? app(RecordSummaryFactory::class)->for($record) : null])
            ->columnSpanFull();
    }

    public static function failureStates(): View
    {
        return View::make('filament.shared.failure-states')
            ->viewData(fn (?Model $record) => ['states' => $record ? FailureState::detect($record) : []])
            ->columnSpanFull();
    }

    public static function timeline(string $subjectType): View
    {
        return View::make('filament.shared.timeline')
            ->viewData(fn (?Model $record) => ['entries' => $record ? app(TimelineQuery::class)->for($subjectType, (string) $record->getKey(), app(TenantContext::class)->id()) : []])
            ->columnSpanFull();
    }

    public static function documentViewer(): View
    {
        return View::make('filament.shared.document-viewer')
            ->viewData(fn (?Model $record) => $record && auth()->user() ? app(DocumentPanelQuery::class)->for($record, auth()->user()) : ['rows' => [], 'withheld' => 0])
            ->columnSpanFull();
    }

    public static function financialPanel(): View
    {
        return View::make('filament.shared.financial-panel')
            ->viewData(fn (?Model $record) => ['rows' => $record ? app(FinancialPanelQuery::class)->for($record) : []])
            ->columnSpanFull();
    }

    /**
     * Canonical handoff: distinct gross premium / fees / commission / carrier
     * settlement lines. Default source is FinancialBreakdownQuery (persisted
     * figures only); a resource may pass its own closure returning
     * ['lines' => [key => ?minor], 'currency' => 'XAF', 'total' => ?minor] or null.
     *
     * @param  (Closure(Model): ?array)|null  $source
     */
    public static function financialBreakdown(?Closure $source = null): View
    {
        return View::make('filament.shared.financial-breakdown-slot')
            ->viewData(function (?Model $record) use ($source): array {
                $data = $record ? ($source ? $source($record) : app(\App\Application\WebExperiences\FinancialBreakdownQuery::class)->for($record)) : null;

                return ['breakdown' => $data];
            })
            ->columnSpanFull();
    }

    /**
     * Canonical handoff persistent payment state panel.
     *
     * @param  Closure(Model): ?array  $source  see resources/views/filament/shared/payment-state.blade.php
     */
    public static function paymentState(Closure $source): View
    {
        return View::make('filament.shared.payment-state-slot')
            ->viewData(fn (?Model $record) => ['payment' => $record ? $source($record) : null])
            ->columnSpanFull();
    }

    public static function relatedRecords(): View
    {
        return View::make('filament.shared.related-records')
            ->viewData(fn (?Model $record) => ['groups' => $record ? app(\App\Application\WebExperiences\RelatedRecordsQuery::class)->for($record) : []])
            ->columnSpanFull();
    }

    /** @param  Closure(Model): ?int  $amountMinor */
    public static function authorityWidget(string $action, Closure $amountMinor, ?Closure $currency = null): View
    {
        return View::make('filament.shared.authority-widget')
            ->viewData(fn (?Model $record) => ['assessment' => $record && auth()->user()
                ? app(AuthorityAssessor::class)->assess(auth()->user(), $action, $amountMinor($record), $currency ? $currency($record) : null)
                : null])
            ->columnSpanFull();
    }

    /**
     * The standard detail layout: header, failure banners, then tabs.
     *
     * @param  array<int, \Filament\Schemas\Components\Component>  $overview
     * @param  array<int, Tabs\Tab>  $extra  record-specific tabs, placed after Financial (e.g. claim payments / disputes / recoveries)
     */
    public static function detailTabs(string $subjectType, array $overview = [], ?array $authority = null, bool $related = false, array $without = [], array $extra = []): array
    {
        $tabs = [];
        if ($overview !== []) {
            $tabs[] = Tabs\Tab::make(__('web_experience.tabs.overview'))->schema($overview);
        }
        $tabs[] = Tabs\Tab::make(__('web_experience.tabs.timeline'))->schema([self::timeline($subjectType)]);
        if (! in_array('documents', $without, true)) {
            $tabs[] = Tabs\Tab::make(__('web_experience.tabs.documents'))->schema([self::documentViewer()]);
        }
        if (! in_array('financial', $without, true)) {
            $tabs[] = Tabs\Tab::make(__('web_experience.tabs.financial'))->schema([self::financialBreakdown(), self::financialPanel()]);
        }
        array_push($tabs, ...$extra);
        if ($related) {
            $tabs[] = Tabs\Tab::make(__('web_experience.tabs.related'))->schema([self::relatedRecords()]);
        }
        if ($authority !== null) {
            $tabs[] = Tabs\Tab::make(__('web_experience.tabs.authority'))->schema([self::authorityWidget(...$authority)]);
        }

        return [self::detailHeader(), self::failureStates(), Tabs::make('record')->tabs($tabs)->columnSpanFull()];
    }
}
