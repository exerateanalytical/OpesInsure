<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Application\Regulatory\CimaSetupService;
use App\Filament\Admin\Concerns\CimaRegulatoryAccess;
use App\Filament\Admin\Concerns\ServiceValidation;
use App\Filament\Admin\Resources\CimaInsurerAuthorizations\CimaInsurerAuthorizationResource;
use App\Models\Carrier;
use App\Models\InsuranceLine;
use App\Models\InsuranceProduct;
use App\Models\Regulatory\RegulatoryReportingCategory;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/** REQ-CIMA-005 - INS-SET-CIMA-001..006: one insurer's CIMA setup (authorization, branches, mappings, reporting). */
final class CimaInsurerSetup extends Page
{
    use CimaRegulatoryAccess;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $navigationLabel = 'Insurer CIMA setup';

    protected static ?int $navigationSort = 213;

    protected static ?string $slug = 'cima-insurer-setup';

    protected string $view = 'filament.admin.pages.cima-insurer-setup';

    public ?string $carrierId = null;

    public static function canAccess(): bool
    {
        return static::canAccessCima();
    }

    public function mount(): void
    {
        $this->carrierId = request()->query('carrier') ?: Carrier::orderBy('legal_name')->value('id');
    }

    public function getTitle(): string
    {
        return 'Insurer CIMA setup';
    }

    protected function getHeaderActions(): array
    {
        return [
            CimaInsurerAuthorizationResource::createAction($this->carrierId),
            Actions\Action::make('reportingMapping')->label('Add reporting mapping')->icon(Heroicon::OutlinedPlus)->visible(fn () => $this->carrierId !== null)
                ->schema([
                    Forms\Components\Select::make('subject_type')->options(['INSURANCE_LINE' => 'Class (insurance line)', 'INSURANCE_PRODUCT' => 'Product'])->required()->live(),
                    Forms\Components\Select::make('subject_code')->label('Class / product')->required()->searchable()
                        ->options(fn ($get) => $get('subject_type') === 'INSURANCE_PRODUCT'
                            ? InsuranceProduct::where('carrier_id', $this->carrierId)->orderBy('code')->pluck('code', 'code')->all()
                            : InsuranceLine::orderBy('code')->pluck('code', 'code')->all()),
                    Forms\Components\Select::make('target_code')->label('Article 411 category')->required()->searchable()
                        ->options(fn () => RegulatoryReportingCategory::where('kind', 'ART_411_CATEGORY')->where('status', 'ACTIVE')->orderBy('sequence')->pluck('code', 'code')->all()),
                    Forms\Components\DatePicker::make('effective_from')->required()->default(now()),
                    Forms\Components\Textarea::make('notes'),
                ])
                ->action(function (array $data) {
                    if (ServiceValidation::run(fn () => app(CimaSetupService::class)->addReportingMapping('INS-SET-CIMA-004', $this->carrierId, $data, auth()->user()))) {
                        Notification::make()->title('Reporting mapping added')->success()->send();
                    }
                }),
        ];
    }

    protected function getViewData(): array
    {
        $carrier = $this->carrierId ? Carrier::find($this->carrierId) : null;

        return [
            'carriers' => Carrier::orderBy('legal_name')->get()->mapWithKeys(fn ($c) => [$c->id => ($c->legal_name ?: $c->cima_code).($c->is_demo ? ' (DEMO)' : '')])->all(),
            'setup' => $carrier ? app(CimaSetupService::class)->insurerSetup($carrier) : null,
        ];
    }
}
