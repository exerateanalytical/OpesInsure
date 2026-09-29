<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\OperationsDesk;

use App\Filament\Shared\Actions\ReleaseAssuranceActions;
use App\Models\ReleaseCandidate;
use App\Models\ReleaseGateResult;
use App\Models\SecurityFinding;
use BackedEnum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Wave 11 release assurance (platform-level): release candidates with gate results and open blocking findings.
 * There is no GET API; the screen opens for any holder of a release-assurance permission (ReleaseCandidatePolicy uses
 * releases.view for listing). Actions: ReleaseAssuranceActions.
 */
final class ReleaseAssurance extends OperationsDeskPage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-rocket';

    protected static ?int $navigationSort = 95;

    protected static ?string $slug = 'release-assurance';

    protected static array $permissions = ['releases.view', 'releases.create', 'releases.assess', 'releases.certify',
        'releases.security-findings.create', 'releases.recovery-exercises.create'];

    protected static string $screen = 'release_assurance';

    protected static string $group = 'Administration';

    public function table(Table $table): Table
    {
        return $table
            ->query(ReleaseCandidate::query()->latest())
            ->columns([
                TextColumn::make('version')->label(self::col('version'))->searchable(),
                TextColumn::make('commit_sha')->label(self::col('commit_sha'))->limit(12),
                TextColumn::make('environment')->label(self::col('environment'))->badge(),
                TextColumn::make('status')->label(self::col('status'))->badge(),
                TextColumn::make('gates_passed')->label(self::col('gates_passed'))
                    ->state(fn (ReleaseCandidate $record) => ReleaseGateResult::query()->where('release_candidate_id', $record->getKey())->where('status', 'PASS')->count()
                        .' / '.count(\App\Domain\Release\ReleaseGate::cases())),
                TextColumn::make('blocking_findings')->label(self::col('blocking_findings'))
                    ->state(fn (ReleaseCandidate $record) => SecurityFinding::query()->where('release_candidate_id', $record->getKey())
                        ->whereIn('severity', ['CRITICAL', 'HIGH'])->where('status', '!=', 'RESOLVED')->count()),
                TextColumn::make('approved_at')->label(self::col('approved_at'))->dateTime(),
            ])
            ->headerActions([ReleaseAssuranceActions::createCandidate(), ReleaseAssuranceActions::recordFinding(), ReleaseAssuranceActions::planRecovery()])
            ->recordActions([ReleaseAssuranceActions::recordGate(), ReleaseAssuranceActions::certify()])
            ->emptyStateHeading(__('operations_actions.empty'));
    }
}
