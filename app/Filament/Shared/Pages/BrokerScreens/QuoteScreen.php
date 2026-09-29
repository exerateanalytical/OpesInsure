<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\BrokerScreens;

use App\Filament\Admin\Resources\Quotes\QuoteResource;
use App\Models\Quote;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\{Builder, Model};

/** Quotes of the caller's book (portal tenant + PortalScope::narrowTable('quotes')), opening the quote detail page. */
abstract class QuoteScreen extends BrokerScreen
{
    protected static array $readPermissions = ['quotes.read'];

    protected function quotes(): Builder
    {
        return $this->book(Quote::class, 'quotes')->with('party')->withCount('offers');
    }

    protected function columns(): array
    {
        return [
            TextColumn::make('reference')->label(self::col('quote'))->state(fn (Quote $record) => strtoupper(substr((string) $record->id, -8)))->copyable()
                ->copyableState(fn (Quote $record) => (string) $record->id),
            self::text('party.display_name', 'customer')->searchable(),
            self::text('line_code', 'line')->sortable(),
            self::status(),
            self::text('channel', 'channel'),
            TextColumn::make('offers_count')->label(self::col('offers'))->alignEnd(),
            self::date('expires_at', 'expires_at'),
            self::date('created_at', 'created_at'),
        ];
    }

    protected function recordLink(Model $record): ?string
    {
        return self::viewUrl(QuoteResource::class, $record);
    }
}
