<?php

declare(strict_types=1);

use App\Application\Claims\ClaimLifecycleService;
use App\Application\Claims\ClaimTransitions;
use App\Domain\Claims\ClaimLifecycle;
use App\Domain\Claims\ClaimMachine;
use App\Domain\Claims\ClaimStateMachine;
use App\Domain\Claims\ClaimTransitionBlocked;
use App\Domain\Claims\ClaimTransitionGuard;
use App\Domain\Shared\StateMachine\StateMachineRegistry;
use App\Domain\Tenancy\TenantContext;
use App\Models\Claim;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';

final class FakeBlockingClaimGuard implements ClaimTransitionGuard
{
    public array $seen = [];

    public function events(): array
    {
        return ['approve', 'register'];
    }

    public function check(Claim $claim, string $event, array $context): ?string
    {
        $this->seen[] = [$event, $context['from'], $context['to'], $context['to_status'], $context['reason_code']];

        return $event === 'register' ? 'FAKE_FRAUD_HOLD' : null;
    }
}

function c1Claim(string $status = 'SUBMITTED'): array
{
    $f = makeMobileCustomerFixture('+2376700'.random_int(10000, 99999));
    $policy = makeMobileTestPolicy($f['proposal'], $f['tenant'], $f['carrier']->id, $f['party']->id);
    $claim = makeMobileTestClaim($f['tenant'], $policy, $f['party'], ['status' => $status]);
    app(TenantContext::class)->set($f['tenant']->id);

    return [$claim, $f['user']];
}

it('covers every blueprint state exactly once through the stored-status mapping', function () {
    $blueprint = ['DRAFT', 'SUBMITTED', 'REGISTERED', 'INFORMATION_REQUIRED', 'UNDER_ASSESSMENT', 'INVESTIGATING', 'DECISION_PENDING', 'APPROVED',
        'PARTIALLY_APPROVED', 'REJECTED', 'APPEALED', 'SETTLEMENT_PENDING', 'SETTLED', 'CLOSED', 'REOPENED'];
    expect(array_values(ClaimMachine::BLUEPRINT))->toEqualCanonicalizing($blueprint)
        ->and(array_keys(ClaimMachine::definition()->states()))->toEqualCanonicalizing(array_keys(ClaimMachine::BLUEPRINT))
        ->and(ClaimMachine::storedStatus('REJECTED'))->toBe('DECLINED')
        ->and(ClaimMachine::storedStatus('ACKNOWLEDGED'))->toBe('ACKNOWLEDGED')
        ->and(ClaimMachine::blueprintState('DISPUTED'))->toBe('APPEALED')
        ->and(app(StateMachineRegistry::class)->get(ClaimMachine::NAME)->name)->toBe('claim');
});

it('is one machine that is a superset of both legacy claim definitions (REQ-DUP-006)', function () {
    foreach ([[ClaimStateMachine::class, 'T'], [ClaimLifecycle::class, 'TRANSITIONS']] as [$class, $const]) {
        foreach ((new ReflectionClassConstant($class, $const))->getValue() as $from => $tos) {
            foreach ($tos as $to) {
                expect(ClaimMachine::transitionTo($from, $to))->not->toBeNull("{$class} {$from}->{$to}");
            }
        }
    }
});

it('supports the blueprint paths incl. information required, investigating, partial approval, appeal and reopen', function () {
    $path = fn (array $s) => collect(range(0, count($s) - 2))->every(fn ($i) => ClaimMachine::transitionTo($s[$i], $s[$i + 1]) !== null);
    expect($path(['DRAFT', 'SUBMITTED', 'REGISTERED', 'INFORMATION_REQUIRED', 'UNDER_ASSESSMENT', 'INVESTIGATING', 'DECISION_PENDING', 'PARTIALLY_APPROVED', 'APPEALED', 'DECISION_PENDING', 'REJECTED', 'CLOSED', 'REOPENED', 'UNDER_ASSESSMENT']))->toBeTrue()
        ->and($path(['DECISION_PENDING', 'APPROVED', 'SETTLEMENT_PENDING', 'SETTLED', 'CLOSED']))->toBeTrue()
        ->and(ClaimMachine::transitionTo('SUBMITTED', 'APPROVED'))->toBeNull()
        ->and(ClaimMachine::transitionTo('CLOSED', 'ASSESSMENT'))->toBeNull();
});

it('moves a claim through the lifecycle service on the shared engine and records machine history', function () {
    [$claim, $user] = c1Claim();
    $s = app(ClaimLifecycleService::class);
    $s->transition($claim, 'ACKNOWLEDGED', 'TRIAGED', [], $user);
    $s->transition($claim, 'ASSESSMENT', 'START', [], $user);
    $c = $s->transition($claim, 'INVESTIGATING', 'SIU_REFERRAL', [], $user);
    // blueprint names are accepted and stored as the existing codes
    $c = $s->transition($c, 'INFORMATION_REQUIRED', 'NEED_DOCS', [], $user);
    expect($c->status)->toBe('EVIDENCE_PENDING')
        ->and(DB::table('workflow_transition_history')->where(['machine' => 'claim', 'subject_id' => $claim->id])->orderBy('occurred_at')->pluck('event')->all())
        ->toBe(['register', 'start_assessment', 'investigate', 'request_information'])
        ->and(DB::table('claim_events')->where('claim_id', $claim->id)->count())->toBe(4);

    expect(fn () => $s->transition($c, 'APPROVED', 'SKIP', [], $user))->toThrow(DomainException::class, 'Invalid claim transition from EVIDENCE_PENDING to APPROVED.');
});

it('runs container-tagged guards before a transition and blocks with the reason code', function () {
    $guard = new FakeBlockingClaimGuard();
    app()->instance(FakeBlockingClaimGuard::class, $guard);
    app()->tag([FakeBlockingClaimGuard::class], ClaimTransitions::GUARD_TAG);
    [$claim, $user] = c1Claim();

    try {
        app(ClaimLifecycleService::class)->transition($claim, 'ACKNOWLEDGED', 'TRIAGED', [], $user);
        $this->fail('guard did not block');
    } catch (ClaimTransitionBlocked $e) {
        expect($e->reasonCode)->toBe('FAKE_FRAUD_HOLD')->and($e->event)->toBe('register')
            ->and($e->errors())->toBe(['status' => ['FAKE_FRAUD_HOLD']]);
    }
    expect($claim->refresh()->status)->toBe('SUBMITTED')
        ->and(DB::table('claim_events')->where('claim_id', $claim->id)->count())->toBe(0)
        ->and(DB::table('workflow_transition_history')->where('subject_id', $claim->id)->count())->toBe(0)
        ->and($guard->seen)->toBe([['register', 'SUBMITTED', 'REGISTERED', 'ACKNOWLEDGED', 'TRIAGED']]);

    // a guard only sees the events it declares
    [$other, $u2] = c1Claim('ACKNOWLEDGED');
    app(ClaimLifecycleService::class)->transition($other, 'ASSESSMENT', 'START', [], $u2);
    expect($other->refresh()->status)->toBe('ASSESSMENT')->and($guard->seen)->toHaveCount(1);
});

it('leaves no runtime caller on the retired legacy claim definitions', function () {
    $hits = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $file) {
        if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), 'Domain/Claims/') || str_contains($file->getPathname(), 'Adapters/ClaimMachineAdapter.php')) {
            continue;
        }
        if (preg_match('/\\\\Claims\\\\(ClaimLifecycle|ClaimStateMachine)\b|\b(ClaimLifecycle|ClaimStateMachine)\s*\$/', file_get_contents($file->getPathname()))) {
            $hits[] = $file->getPathname();
        }
    }
    expect($hits)->toBe([]);
});
