<?php

use App\Filament\Admin\Resources\Quotes\QuoteResource;
use App\Filament\Shared\Columns;
use App\Filament\Shared\LocalizedResource;
use Filament\Tables\Columns\TextColumn;

/** UI QA 2026-09-27: resource titles, column labels and status badges were English for French users. */
beforeEach(fn () => Columns::applyDefaults());

it('translates resource titles, generated column labels and status badges for a French user', function () {
    app()->setLocale('fr');

    expect(QuoteResource::getTitleCasePluralModelLabel())->toBe('Devis')
        ->and(TextColumn::make('customer_number')->getLabel())->toBe(__('Customer number'))
        ->and(__('Customer number'))->not->toBe('Customer number')
        ->and(Columns::humanise('ACTIVE'))->toBe('Actif');
});

it('translates section headings, tabs and notification titles for a French user', function () {
    app()->setLocale('fr');

    expect(\Filament\Schemas\Components\Section::make('Audit & history')->getHeading())->toBe(__('Audit & history'))
        ->and(__('Audit & history'))->not->toBe('Audit & history')
        ->and(\Filament\Schemas\Components\Tabs\Tab::make('Overview')->getLabel())->toBe(__('Overview'))
        ->and(\Filament\Notifications\Notification::make()->title('Customer status updated')->getTitle())->toBe(__('Customer status updated'))
        ->and(\Filament\Notifications\Notification::make()->title('No such text 42')->getTitle())->toBe('No such text 42');
});

it('keeps English unchanged and Title Case for an English user', function () {
    app()->setLocale('en');

    expect(QuoteResource::getTitleCasePluralModelLabel())->toBe('Quotes')
        ->and(TextColumn::make('customer_number')->getLabel())->toBe('Customer number')
        ->and(Columns::humanise('PENDING_REVIEW'))->toBe('Pending review');
});

it('never returns a lang group array for a label that matches a lang file name', function () {
    app()->setLocale('fr');

    expect(LocalizedResource::t('quotes'))->toBeString()
        ->and(__('Security'))->toBeString();
});
