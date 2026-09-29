<?php
namespace App\Filament\Admin\Resources\Quotes;
use App\Domain\Tenancy\TenantContext;use App\Filament\Admin\Resources\Quotes\Pages;use App\Models\Quote;use BackedEnum;use Filament\Actions;use Filament\Resources\Resource;use Filament\Schemas\Schema;use Filament\Tables;use Filament\Tables\Table;use Illuminate\Database\Eloquent\Builder;
final class QuoteResource extends \App\Filament\Shared\LocalizedResource{protected static?string$model=Quote::class;protected static string|BackedEnum|null$navigationIcon='lucide-scale';protected static string|\UnitEnum|null$navigationGroup='Sales workspace';protected static?int$navigationSort=41;public static function getEloquentQuery():Builder{return \App\Application\WebExperiences\PortalScope::narrowTable(parent::getEloquentQuery()->where('tenant_id',app(TenantContext::class)->id()),'quotes')->withCount('offers');}public static function form(Schema$s):Schema{return$s->components([]);}
    public static function infolist(Schema $s): Schema { return $s->components(\App\Filament\Shared\Components\RecordShell::detailTabs('quote', self::overview(), null, true, ['financial'])); }
    /** Overview tab (CoreRecordOverview pattern). */
    public static function overview(): array { $o = \App\Filament\Shared\Components\CoreRecordOverview::class; return [
        $o::section('quote', [$o::text('quote_number', 'number')->copyable(), $o::status(), $o::text('party.display_name', 'customer'), $o::text('line_code', 'line')->badge(), $o::text('channel')->badge(), $o::text('version')]),
        $o::section('lifecycle', [$o::date('submitted_at', 'submitted', true), $o::date('rated_at', 'rated', true), $o::date('expires_at', 'expires', true), $o::date('accepted_at', 'accepted', true), $o::date('declined_at', 'declined', true), $o::text('decline_reason_code', 'decline_reason')]),
        // P5 2026-09-29: the carrier offers side by side, ranked as rated (cheapest total first) — compare before converting.
        \Filament\Schemas\Components\Section::make(__('broker_portal_sales.offers.title'))->columnSpanFull()->schema([
            \Filament\Infolists\Components\RepeatableEntry::make('offers')->hiddenLabel()->columns(6)->placeholder(__('broker_portal_sales.offers.empty'))->schema([
                \Filament\Infolists\Components\TextEntry::make('comparison_rank')->label(__('broker_portal_sales.offers.rank'))->placeholder('—'),
                \Filament\Infolists\Components\TextEntry::make('carrier.party.display_name')->label(__('broker_portal_sales.offers.carrier'))->placeholder('—'),
                \Filament\Infolists\Components\TextEntry::make('product.name')->label(__('broker_portal_sales.offers.product'))->placeholder('—'),
                \Filament\Infolists\Components\TextEntry::make('premium_minor')->label(__('broker_portal_sales.offers.premium'))->numeric(),
                \Filament\Infolists\Components\TextEntry::make('total_minor')->label(__('broker_portal_sales.offers.total'))->numeric()->suffix(fn ($record) => ' '.($record->currency ?? '')),
                \Filament\Infolists\Components\TextEntry::make('status')->label(__('broker_portal_sales.offers.status'))->badge(),
            ]),
        ]),
    ]; }
    public static function table(Table$t):Table{return \App\Filament\Shared\Concerns\ListScreen::apply($t->columns([Tables\Columns\TextColumn::make('id')->label('Quote')->copyable()->limit(12),Tables\Columns\TextColumn::make('party.display_name')->label('Customer')->searchable(),Tables\Columns\TextColumn::make('line_code')->badge(),Tables\Columns\TextColumn::make('channel')->badge(),Tables\Columns\TextColumn::make('offers_count')->label('Offers'),\App\Filament\Shared\Columns::status('status'),\App\Filament\Shared\Columns::date('expires_at')])->recordActions([Actions\ViewAction::make()])->emptyStateHeading('No quotes yet')->emptyStateDescription('Submitted quotes appear here with ranked carrier offers and transparent price breakdowns.')->emptyStateIcon('lucide-scale'),'quotes');}public static function getPages():array{return['index'=>Pages\ListQuotes::route('/'),'view'=>Pages\ViewQuote::route('/{record}')];}}
