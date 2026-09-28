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
