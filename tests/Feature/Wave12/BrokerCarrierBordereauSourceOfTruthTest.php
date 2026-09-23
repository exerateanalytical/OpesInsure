<?php

declare(strict_types=1);

use App\Application\FinancialDistribution\BordereauService;
use App\Models\Bordereau;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

require_once __DIR__.'/Concerns/mobile_customer_helpers.php';

/**
 * The Wave6 BordereauService is the governed source of truth for the
 * bordereaux table (maker-checker, advisory locks, content hashing,
 * idempotency, event + outbox writes) — as opposed to the raw
 * DB::table('bordereaux') writes in BrokerOperationsController /
 * CarrierOperationsController.
 *
 * Until this batch it could not actually run: App\Models\Bordereau set no
 * $table, so Eloquent resolved it to "bordereaus" and every call threw
 * "relation bordereaus does not exist". The Wave6 suite never caught it
 * because those tests only string-match the service source. These tests
 * exercise the real thing against the real schema so that regression
 * cannot come back unnoticed.
 */
it('runs the full governed bordereau lifecycle through BordereauService against the real table', function () {
    $fixture = makeMobilePartnerFixture('BROKER', '+237670000240');
    $chain = makeMobileFinanceProposalChain($fixture['tenant']);
    makeMobileTestPolicy($chain['proposal'], $fixture['tenant'], $chain['carrier']->id, $chain['party']->id, ['issued_at' => now()]);

    $preparer = $fixture['user'];
    $approver = makeMobileTenantStaffUser($fixture['tenant'], '+237670000241');
    $service = app(BordereauService::class);

    $bordereau = $service->prepare([
        'tenant_id' => $fixture['tenant']->id,
        'carrier_id' => $chain['carrier']->id,
        'type' => 'PREMIUM',
        'period_start' => now()->subDay()->toDateString(),
        'period_end' => now()->addDay()->toDateString(),
        'currency' => 'XAF',
        'idempotency_key' => (string) Str::uuid(),
    ], $preparer);

    expect($bordereau->status)->toBe('DRAFT');
    expect($bordereau->item_count)->toBe(1);
    expect($bordereau->content_hash)->not->toBeEmpty();

    // Maker-checker: the preparer may not approve their own bordereau.
    expect(fn () => $service->approve($bordereau, $preparer))->toThrow(ValidationException::class);

    $service->approve($bordereau, $approver);
    expect($bordereau->refresh()->status)->toBe('APPROVED');

    $service->submit($bordereau, $approver);
    expect($bordereau->refresh()->status)->toBe('SUBMITTED');

    $service->acknowledge($bordereau, 'CARRIER-ACK-1', $approver);
    expect($bordereau->refresh()->status)->toBe('ACKNOWLEDGED');
    expect($bordereau->carrier_reference)->toBe('CARRIER-ACK-1');

    // The governed path records a transition trail the raw-SQL path does not.
    $this->assertDatabaseHas('financial_distribution_events', [
        'aggregate_type' => 'BORDEREAU', 'aggregate_id' => $bordereau->id, 'to_status' => 'ACKNOWLEDGED',
    ]);
});

it('replays a repeated idempotency key instead of preparing a second bordereau', function () {
    $fixture = makeMobilePartnerFixture('BROKER', '+237670000242');
    $chain = makeMobileFinanceProposalChain($fixture['tenant']);
    makeMobileTestPolicy($chain['proposal'], $fixture['tenant'], $chain['carrier']->id, $chain['party']->id, ['issued_at' => now()]);

    $service = app(BordereauService::class);
    $payload = [
        'tenant_id' => $fixture['tenant']->id,
        'carrier_id' => $chain['carrier']->id,
        'type' => 'PREMIUM',
        'period_start' => now()->subDay()->toDateString(),
        'period_end' => now()->addDay()->toDateString(),
        'currency' => 'XAF',
        'idempotency_key' => (string) Str::uuid(),
    ];

    $first = $service->prepare($payload, $fixture['user']);
    $second = $service->prepare($payload, $fixture['user']);

    expect($second->id)->toBe($first->id);
    expect(Bordereau::where('tenant_id', $fixture['tenant']->id)->count())->toBe(1);
});

it('maps the Bordereau model to the bordereaux table, not Eloquent\'s default pluralisation', function () {
    expect((new Bordereau)->getTable())->toBe('bordereaux');
});
