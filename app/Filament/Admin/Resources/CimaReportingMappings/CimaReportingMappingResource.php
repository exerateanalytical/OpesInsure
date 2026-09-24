<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CimaReportingMappings;

use App\Application\Audit\AuditWriter;
use App\Filament\Admin\Concerns\CimaRegulatoryAccess;
use App\Models\InsuranceLine;
use App\Models\InsuranceProduct;
use App\Models\Regulatory\RegulatoryReportingCategory;
use App\Models\Regulatory\RegulatoryReportingMapping;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;

/** PLT-CIMA-012 normalized class / product → Article 411 reporting category. Effective-dated; ended, never deleted. */
final class CimaReportingMappingResource extends Resource
{
    use CimaRegulatoryAccess;

    protected static ?string $model = RegulatoryReportingMapping::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static ?string $navigationLabel = 'Reporting mapping';

    protected static ?string $modelLabel = 'reporting mapping';

    protected static ?int $navigationSort = 213;

    public static function createAction(): Actions\Action
    {
        return Actions\Action::make('map')->label('Add reporting mapping')->icon(Heroicon::OutlinedPlus)
            ->visible(fn () => static::canAccessCima())
            ->schema([
                Forms\Components\Select::make('subject_type')->options(['INSURANCE_LINE' => 'Normalized class (insurance line)', 'INSURANCE_PRODUCT' => 'Product'])->required()->live(),
                Forms\Components\Select::make('subject_code')->label('Class / product')->required()->searchable()
                    ->options(fn ($get) => $get('subject_type') === 'INSURANCE_PRODUCT'
                        ? InsuranceProduct::orderBy('code')->pluck('code', 'code')->all()
                        : InsuranceLine::orderBy('code')->pluck('code', 'code')->all()),
                Forms\Components\Select::make('reporting_category_code')->label('Article 411 category')->required()->searchable()
                    ->options(fn () => RegulatoryReportingCategory::where('kind', 'ART_411_CATEGORY')->where('status', 'ACTIVE')->orderBy('sequence')->pluck('code', 'code')->all()),
                Forms\Components\DatePicker::make('effective_from')->required()->default(now()),
                Forms\Components\Textarea::make('notes'),
            ])
            ->action(function (array $data) {
                $m = RegulatoryReportingMapping::create($data + ['regime' => 'CIMA', 'status' => 'ACTIVE', 'created_by' => auth()->id()]);
                app(AuditWriter::class)->record('regulatory.reporting_mapping.created', 'regulatory_reporting_mapping', $m->id, $data);
                Notification::make()->title('Reporting mapping added')->success()->send();
            });
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('subject_code')->columns([
            Tables\Columns\TextColumn::make('subject_type')->badge(),
            Tables\Columns\TextColumn::make('subject_code')->label('Class / product')->searchable(),
            Tables\Columns\TextColumn::make('reporting_category_code')->label('Article 411 category')->fontFamily('mono'),
            Tables\Columns\TextColumn::make('status')->badge(),
            Tables\Columns\TextColumn::make('effective_from')->date(),
            Tables\Columns\TextColumn::make('effective_until')->date()->placeholder('Open'),
        ])
            ->recordActions([
                Actions\Action::make('end')->label('End mapping')->color('gray')->requiresConfirmation()
                    ->visible(fn (RegulatoryReportingMapping $m) => $m->effective_until === null)
                    ->action(function (RegulatoryReportingMapping $m) {
                        $m->update(['effective_until' => now()->toDateString(), 'status' => 'ENDED']);
                        app(AuditWriter::class)->record('regulatory.reporting_mapping.ended', 'regulatory_reporting_mapping', $m->id);
                    }),
            ])
            ->emptyStateHeading('No reporting mappings yet')
            ->emptyStateDescription('Map each normalized class or product to its Article 411 reporting category.');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListCimaReportingMappings::route('/')];
    }
}
