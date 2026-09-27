<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PolicyIssuances\Pages;

use App\Application\Policies\PolicyIssuanceService;
use App\Filament\Admin\Resources\PolicyIssuances\PolicyIssuanceResource;
use App\Filament\Shared\Actions\IssuanceActions;
use App\Filament\Shared\Components\RecordInfolist;
use App\Filament\Shared\Pages\RecordDetailPage;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/** Issuance request detail: maker-checker stage, approval trail and the verify / correct / approve / second-approve / reject actions. */
final class ViewPolicyIssuance extends RecordDetailPage
{
    protected static string $resource = PolicyIssuanceResource::class;

    public function infolist(Schema $schema): Schema
    {
        $svc = app(PolicyIssuanceService::class);

        return $schema->components([
            Section::make(__('issuance_maker_checker.approvals'))->columns(2)->schema([
                TextEntry::make('maker_checker_stage')->label(__('issuance_maker_checker.stage'))->badge()
                    ->state(fn ($record) => __('issuance_maker_checker.stages.'.$svc->stage($record))),
                TextEntry::make('correction_reason')->label(__('issuance_maker_checker.correction_reason'))->visible(fn ($record) => filled($record->correction_reason)),
                RepeatableEntry::make('approval_trail')->hiddenLabel()->columnSpanFull()->columns(3)
                    ->state(fn ($record) => array_map(fn ($a) => ['step' => __('issuance_maker_checker.steps.'.$a['step']), 'name' => $a['name'] ?? $a['user_id'], 'at' => $a['at']], $svc->approvals($record)))
                    ->schema([TextEntry::make('step')->hiddenLabel()->badge(), TextEntry::make('name')->hiddenLabel(), TextEntry::make('at')->hiddenLabel()->dateTime()]),
            ]),
            ...RecordInfolist::for($this->getRecord(), static::$auditSubjectType),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [IssuanceActions::group()];
    }
}
