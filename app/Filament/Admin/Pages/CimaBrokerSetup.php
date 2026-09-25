<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Application\Regulatory\CimaSetupService;
use App\Filament\Admin\Concerns\CimaRegulatoryAccess;
use App\Filament\Admin\Concerns\ServiceValidation;
use App\Models\InsuranceLine;
use App\Models\InsuranceProduct;
use App\Models\Partner;
use App\Models\Regulatory\RegulatoryReportingCategory;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/** REQ-CIMA-005 - BRK-SET-CIMA-001..004: one intermediary's Article 557 / Article 411 reporting setup. */
final class CimaBrokerSetup extends Page
{
    use CimaRegulatoryAccess;

    /** Subject vocabularies for the premium/commission screens (platform codes, not regulatory facts). */
    public const PREMIUM_STATUSES = ['ISSUED' => 'Premium issued (emise)', 'COLLECTED' => 'Premium collected (encaissee)'];

    public const COMMISSION_TYPES = ['BROKER_COMMISSION' => 'Broker commission', 'AGENT_COMMISSION' => 'Agent commission'];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBriefcase;

    protected static ?string $navigationLabel = 'Broker CIMA setup';

    protected static ?int $navigationSort = 214;

    protected static ?string $slug = 'cima-broker-setup';

    protected string $view = 'filament.admin.pages.cima-broker-setup';

    public ?string $partnerId = null;

    public static function canAccess(): bool
    {
        return static::canAccessCima();
    }

    public function mount(): void
    {
        $this->partnerId = request()->query('partner') ?: Partner::whereIn('type', ['BROKER', 'AGENT'])->orderBy('legal_name')->value('id');
    }

    public function getTitle(): string
    {
        return 'Broker CIMA setup';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('mapping')->label('Add reporting setting')->icon(Heroicon::OutlinedPlus)->visible(fn () => $this->partnerId !== null)
                ->schema([
                    Forms\Components\Select::make('screen')->required()->live()->options([
                        'BRK-SET-CIMA-001' => 'BRK-SET-CIMA-001 Reporting configuration (Art. 557 measure)',
                        'BRK-SET-CIMA-002' => 'BRK-SET-CIMA-002 Regulatory product classification (Art. 411)',
                        'BRK-SET-CIMA-003' => 'BRK-SET-CIMA-003 Premium / collection classification',
                        'BRK-SET-CIMA-004' => 'BRK-SET-CIMA-004 Commission reporting mapping',
                    ]),
                    Forms\Components\Select::make('subject_type')->required()->live()
                        ->options(fn ($get) => match ($get('screen')) {
                            'BRK-SET-CIMA-001' => ['REPORTING_MEASURE' => 'Reported measure'],
                            'BRK-SET-CIMA-003' => ['PREMIUM_STATUS' => 'Premium status'],
                            'BRK-SET-CIMA-004' => ['COMMISSION_TYPE' => 'Commission type'],
                            default => ['INSURANCE_LINE' => 'Class (insurance line)', 'INSURANCE_PRODUCT' => 'Product'],
                        }),
                    Forms\Components\Select::make('subject_code')->required()->searchable()
                        ->options(fn ($get) => match ($get('subject_type')) {
                            'REPORTING_MEASURE' => ['ENABLED' => 'Enabled'],
                            'PREMIUM_STATUS' => self::PREMIUM_STATUSES,
                            'COMMISSION_TYPE' => self::COMMISSION_TYPES,
                            'INSURANCE_PRODUCT' => InsuranceProduct::orderBy('code')->pluck('code', 'code')->all(),
                            default => InsuranceLine::orderBy('code')->pluck('code', 'code')->all(),
                        }),
                    Forms\Components\Select::make('target_code')->label('Article 557 measure / Article 411 category')->required()->searchable()
                        ->options(fn ($get) => $get('screen') === 'BRK-SET-CIMA-002'
                            ? RegulatoryReportingCategory::where('kind', 'ART_411_CATEGORY')->where('status', 'ACTIVE')->orderBy('sequence')->pluck('code', 'code')->all()
                            : RegulatoryReportingCategory::where('kind', 'ART_557_MEASURE')->where('status', 'ACTIVE')
                                ->when(CimaSetupService::SCREEN_MEASURES[$get('screen')] ?? null, fn ($q, $codes) => $q->whereIn('code', $codes))
                                ->orderBy('sequence')->pluck('label_fr', 'code')->all()),
                    Forms\Components\DatePicker::make('effective_from')->required()->default(now()),
                    Forms\Components\Textarea::make('notes'),
                ])
                ->action(function (array $data) {
                    if (ServiceValidation::run(fn () => app(CimaSetupService::class)->addReportingMapping($data['screen'], $this->partnerId, $data, auth()->user()))) {
                        Notification::make()->title('Reporting setting added')->success()->send();
                    }
                }),
        ];
    }

    protected function getViewData(): array
    {
        $partner = $this->partnerId ? Partner::find($this->partnerId) : null;

        return [
            'partners' => Partner::with('party')->whereIn('type', ['BROKER', 'AGENT'])->orderBy('legal_name')->get()
                ->mapWithKeys(fn ($p) => [$p->id => ($p->legal_name ?: $p->party?->display_name ?: $p->id).' - '.$p->type.($p->is_demo ? ' (DEMO)' : '')])->all(),
            'setup' => $partner ? app(CimaSetupService::class)->brokerSetup($partner) : null,
        ];
    }
}
