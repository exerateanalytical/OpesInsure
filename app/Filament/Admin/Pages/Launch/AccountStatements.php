<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Launch;

use App\Application\Finance\Statements\AccountStatementService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/**
 * FIN-009 Customer / FIN-010 Broker / FIN-011 Carrier / FIN-012 Agent account statement — GET
 * finance/statements/{subjectType}/{subject} (statements.read) via AccountStatementService::build (derived, read-only,
 * tenant-scoped). The operator picks the subject and period; the lines, running balance and totals are shown below.
 */
final class AccountStatements extends LaunchScreenPage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-file-spreadsheet';

    protected static ?int $navigationSort = 67;

    protected static ?string $slug = 'finance/account-statements';

    protected static array $permissions = ['statements.read'];

    protected static string $screen = 'account_statements';

    protected static string $group = 'Financial operations';

    /** @var array<string, mixed>|null the last statement built (AccountStatementService::build shape) */
    public ?array $statement = null;

    public function getSubheading(): ?string
    {
        if ($this->statement === null) {
            return __('launch_screens.intro.account_statements');
        }
        $s = $this->statement;
        $money = fn (int $minor) => $s['currency'].' '.number_format($minor / 100, 2);

        return __('launch_screens.statement_summary', [
            'number' => $s['statement_number'], 'name' => $s['subject']['name'] ?? '—', 'from' => $s['period_start'], 'to' => $s['period_end'],
            'opening' => $money((int) $s['opening_balance_minor']), 'closing' => $money((int) $s['closing_balance_minor']),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('build')
                ->label(__('launch_screens.actions.build_statement'))
                ->icon('lucide-file-search')
                ->schema([
                    Select::make('subject_type')->label(self::col('subject_type'))->required()->live()
                        ->options(collect(AccountStatementService::SUBJECTS)->mapWithKeys(fn ($t) => [$t => __('launch_screens.subjects.'.$t)])->all()),
                    Select::make('subject_id')->label(self::col('subject'))->required()->searchable()
                        ->options(fn (Get $get): array => $this->subjects((string) $get('subject_type'))),
                    DatePicker::make('from')->label(self::col('from'))->required()->default(now()->startOfYear()),
                    DatePicker::make('to')->label(self::col('to'))->required()->default(now()),
                    TextInput::make('currency')->label(self::col('currency'))->required()->length(3)->default('XAF'),
                ])
                ->action(function (array $data): void {
                    $this->statement = app(AccountStatementService::class)->build(
                        (string) $this->tenantId, $data['subject_type'], $data['subject_id'], (string) $data['from'], (string) $data['to'], $data['currency'],
                    );
                    $this->resetTable();
                }),
        ];
    }

    /** @return array<string, string> id => name, limited to the signed-in tenant */
    private function subjects(string $type): array
    {
        $t = $this->tenantId;
        if ($t === null) {
            return [];
        }

        return match ($type) {
            'customer' => DB::table('tenant_customers as c')->join('parties as p', 'p.id', '=', 'c.party_id')->where('c.tenant_id', $t)
                ->orderBy('p.display_name')->limit(500)->pluck('p.display_name', 'p.id')->all(),
            'broker', 'agent' => DB::table('partners as x')->join('parties as p', 'p.id', '=', 'x.party_id')
                ->where(fn ($q) => $q->where('x.tenant_id', $t)->orWhereNull('x.tenant_id'))->whereRaw('lower(x.type) = ?', [$type])
                ->orderBy('p.display_name')->limit(500)->pluck('p.display_name', 'x.id')->all(),
            'carrier' => DB::table('carriers as x')->join('parties as p', 'p.id', '=', 'x.party_id')
                ->orderBy('p.display_name')->limit(500)->pluck('p.display_name', 'x.id')->all(),
            default => [],
        };
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (): array {
                $rows = [];
                foreach ($this->statement['lines'] ?? [] as $i => $l) {
                    $rows[(string) $i] = ['__key' => (string) $i, 'id' => (string) $i] + $l;
                }

                return $rows;
            })
            ->columns([
                TextColumn::make('occurred_at')->label(self::col('occurred_at'))->dateTime(),
                TextColumn::make('line_type')->label(self::col('line_type'))->badge(),
                TextColumn::make('description')->label(self::col('description'))->wrap(),
                TextColumn::make('reference_type')->label(self::col('reference'))->placeholder('—'),
                \App\Filament\Shared\Columns::money('amount_minor', 'currency', self::col('amount')),
                \App\Filament\Shared\Columns::money('balance_minor', 'currency', self::col('balance')),
            ])
            ->emptyStateHeading(__('launch_screens.statement_empty'));
    }
}
