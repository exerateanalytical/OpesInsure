<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Application\Audit\AuditWriter;
use App\Application\Release\ReleaseCertificationService;
use App\Domain\Release\ReleaseGate;
use App\Interfaces\Http\Errors\ApiProblemException;
use App\Models\RecoveryExercise;
use App\Models\ReleaseCandidate;
use App\Models\SecurityFinding;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * Release assurance (UI batch 26). Same permission + policy + service + validation as Wave11Controller (routes/wave11.php);
 * the controller's policy check (Gate) and its audited call (action audit row) are applied here too:
 *   releaseCandidateCreate  POST release-assurance/candidates                    releases.create    ReleaseCertificationService::create
 *   releaseGateRecord       POST release-assurance/candidates/{c}/gates          releases.assess    ReleaseCertificationService::record
 *   releaseCertify          POST release-assurance/candidates/{c}/certify        releases.certify   ReleaseCertificationService::certify (≠ creator, all gates PASS)
 *   securityFindingRecord   POST release-assurance/security-findings             releases.security-findings.create   ReleaseCertificationService::recordFinding
 *   recoveryExercisePlan    POST release-assurance/recovery-exercises            releases.recovery-exercises.create  ReleaseCertificationService::planRecoveryExercise
 */
final class ReleaseAssuranceActions
{
    private const L = 'operations_actions';

    public static function createCandidate(): Action
    {
        $p = 'releases.create';

        return WorkflowAction::make('releaseCandidateCreate', $p, self::L)->icon('lucide-package-plus')
            ->schema([
                TextInput::make('version')->label(__(self::L.'.fields.version'))->required()->maxLength(64),
                TextInput::make('commit_sha')->label(__(self::L.'.fields.commit_sha'))->required()->length(40),
                Select::make('environment')->label(__(self::L.'.fields.environment'))->required()->options(self::environments()),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p, fn () => self::audited(
                'create', ReleaseCandidate::class, 'release_candidate', null, 'releases.candidates.create',
                fn () => app(ReleaseCertificationService::class)->create($data, auth()->user())), __(self::L.'.releaseCandidateCreate.done')));
    }

    public static function recordGate(): Action
    {
        $p = 'releases.assess';

        return WorkflowAction::make('releaseGateRecord', $p, self::L)->icon('lucide-list-checks')
            ->visible(fn (ReleaseCandidate $record) => in_array($record->status, ['DRAFT', 'ASSESSING'], true))
            ->schema([
                Select::make('gate')->label(__(self::L.'.fields.gate'))->required()
                    ->options(collect(ReleaseGate::cases())->mapWithKeys(fn (ReleaseGate $g) => [$g->value => __(self::L.'.codes.gate.'.$g->value)])->all()),
                Select::make('status')->label(__(self::L.'.fields.gate_status'))->required()->options(['PASS' => __(self::L.'.codes.gate_status.PASS'), 'FAIL' => __(self::L.'.codes.gate_status.FAIL')]),
                KeyValue::make('evidence')->label(__(self::L.'.fields.evidence'))->required(),
            ])
            ->action(fn (Action $action, ReleaseCandidate $record, array $data) => WorkflowAction::run($action, $p, fn () => self::audited(
                'assess', $record, 'release_candidate', $record->getKey(), 'releases.candidates.gate',
                fn () => app(ReleaseCertificationService::class)->record($record, ReleaseGate::from($data['gate']), $data['status'], (array) $data['evidence'], auth()->user())),
                __(self::L.'.releaseGateRecord.done')));
    }

    public static function certify(): Action
    {
        $p = 'releases.certify';

        return WorkflowAction::make('releaseCertify', $p, self::L)->icon('lucide-shield-check')->requiresConfirmation()
            ->visible(fn (ReleaseCandidate $record) => in_array($record->status, ['DRAFT', 'ASSESSING'], true))
            ->action(fn (Action $action, ReleaseCandidate $record) => WorkflowAction::run($action, $p, fn () => self::audited(
                'certify', $record, 'release_candidate', $record->getKey(), 'releases.candidates.certify',
                fn () => app(ReleaseCertificationService::class)->certify($record, auth()->user(), (int) $record->lock_version)), __(self::L.'.releaseCertify.done')));
    }

    public static function recordFinding(): Action
    {
        $p = 'releases.security-findings.create';

        return WorkflowAction::make('securityFindingRecord', $p, self::L)->icon('lucide-bug')
            ->schema([
                Select::make('release_candidate_id')->label(__(self::L.'.fields.release_candidate'))
                    ->options(fn () => ReleaseCandidate::query()->latest()->limit(100)->pluck('version', 'id')->all()),
                TextInput::make('source')->label(__(self::L.'.fields.source'))->required()->maxLength(32),
                Select::make('severity')->label(__(self::L.'.fields.severity'))->required()
                    ->options(collect(['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'])->mapWithKeys(fn ($s) => [$s => __(self::L.'.codes.severity.'.$s)])->all()),
                TextInput::make('title')->label(__(self::L.'.fields.title'))->required()->maxLength(255),
                Textarea::make('description')->label(__(self::L.'.fields.description'))->required()->maxLength(8000),
                TextInput::make('cve')->label(__(self::L.'.fields.cve'))->maxLength(32),
                DateTimePicker::make('due_at')->label(__(self::L.'.fields.due_at')),
            ])
            ->action(function (Action $action, array $data) use ($p) {
                $d = array_filter([...$data, 'due_at' => filled($data['due_at'] ?? null) ? Carbon::parse($data['due_at'])->toIso8601String() : null], fn ($v) => filled($v));

                return WorkflowAction::run($action, $p, fn () => self::audited('create', SecurityFinding::class, 'security_finding', null, 'releases.security-findings.create',
                    fn () => app(ReleaseCertificationService::class)->recordFinding($d)), __(self::L.'.securityFindingRecord.done'));
            });
    }

    public static function planRecovery(): Action
    {
        $p = 'releases.recovery-exercises.create';

        return WorkflowAction::make('recoveryExercisePlan', $p, self::L)->icon('lucide-life-buoy')
            ->schema([
                Select::make('environment')->label(__(self::L.'.fields.environment'))->required()->options(self::environments()),
                Select::make('exercise_type')->label(__(self::L.'.fields.exercise_type'))->required()
                    ->options(collect(['BACKUP_RESTORE', 'REGION_FAILOVER', 'TABLETOP'])->mapWithKeys(fn ($s) => [$s => __(self::L.'.codes.exercise_type.'.$s)])->all()),
                TextInput::make('target_rto_minutes')->label(__(self::L.'.fields.target_rto_minutes'))->required()->integer()->minValue(1),
                TextInput::make('target_rpo_minutes')->label(__(self::L.'.fields.target_rpo_minutes'))->required()->integer()->minValue(0),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p, fn () => self::audited(
                'create', RecoveryExercise::class, 'recovery_exercise', null, 'releases.recovery-exercises.create',
                fn () => app(ReleaseCertificationService::class)->planRecoveryExercise([...$data, 'target_rto_minutes' => (int) $data['target_rto_minutes'],
                    'target_rpo_minutes' => (int) $data['target_rpo_minutes']])), __(self::L.'.recoveryExercisePlan.done')));
    }

    /** @return array<string, string> */
    private static function environments(): array
    {
        return ['staging' => __(self::L.'.codes.environment.staging'), 'production' => __(self::L.'.codes.environment.production')];
    }

    /**
     * Wave11Controller::gateAuthorize + auditedCall: the model policy is re-asserted (allow / deny audited) and the
     * successful call leaves the action's audit row; a business-rule refusal leaves "<action>.denied".
     */
    private static function audited(string $ability, mixed $arg, string $subjectType, ?string $subjectId, string $auditAction, \Closure $call): mixed
    {
        $audit = app(AuditWriter::class);
        if (! Gate::forUser(auth()->user())->allows($ability, $arg)) {
            $audit->record('authorization.denied', $subjectType, $subjectId, ['ability' => $ability], 'permission_denied');
            throw new ApiProblemException('FORBIDDEN', 403, __('workflow_actions.denied'));
        }
        $audit->record('authorization.allowed', $subjectType, $subjectId, ['ability' => $ability]);
        try {
            $result = $call();
        } catch (\Illuminate\Validation\ValidationException $e) {
            $audit->record($auditAction.'.denied', $subjectType, $subjectId, [], $e->validator->errors()->first());
            throw $e;
        }
        $audit->record($auditAction, $subjectType, $subjectId ?? (is_object($result) && method_exists($result, 'getKey') ? (string) $result->getKey() : null), []);

        return $result;
    }
}
