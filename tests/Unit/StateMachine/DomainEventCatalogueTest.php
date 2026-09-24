<?php

// REQ-ARC-004

use App\Application\Events\Catalogue\DomainEventCatalogue;
use App\Application\Events\Catalogue\EventDefinition;

test('REQ-ARC-004 all 26 WRS required events are aliased to dotted names', function () {
    $wrs = ['CustomerRegistered', 'KYCSubmitted', 'KYCApproved', 'QuoteCalculated', 'QuoteAccepted', 'ProposalSubmitted',
        'UnderwritingApproved', 'PaymentInitiated', 'PaymentSucceeded', 'PaymentFailed', 'PolicyIssued', 'PolicyActivated',
        'AttestationGenerated', 'EndorsementIssued', 'RenewalDue', 'PolicyRenewed', 'ClaimReported', 'ClaimEvidenceReceived',
        'ClaimAssigned', 'ClaimApproved', 'ClaimRejected', 'ClaimSettled', 'RefundApproved', 'CommissionAccrued',
        'CommissionSettled', 'SettlementCompleted'];
    expect($wrs)->toHaveCount(26);
    foreach ($wrs as $alias) {
        expect(DomainEventCatalogue::get($alias)->sources)->toContain(EventDefinition::SOURCE_WRS);
    }
    expect(DomainEventCatalogue::canonicalName('ClaimReported'))->toBe('claim.fnol.submitted');
});

test('REQ-ARC-004 all 15 PRE product events are aliased', function () {
    $pre = ['ProductCreated', 'ProductVersionSubmitted', 'ProductVersionApproved', 'ProductVersionPublished', 'ProductVersionSuspended',
        'ProductVersionRetired', 'TariffApproved', 'TariffActivated', 'EligibilityEvaluated', 'QuoteRated', 'UnderwritingDecided',
        'PolicySnapshotCreated', 'ClaimCoverageEvaluated', 'ClaimSettlementCalculated', 'CommissionCalculated'];
    foreach ($pre as $alias) {
        expect(DomainEventCatalogue::get($alias)->sources)->toContain(EventDefinition::SOURCE_PRE);
    }
});

test('REQ-ARC-004 names are dotted, unique, and aliases are PascalCase', function () {
    foreach (DomainEventCatalogue::all() as $name => $e) {
        expect($name)->toMatch('/^[a-z][a-z_]*(\.[a-z][a-z_]*){1,2}$/');
        foreach ($e->aliases as $a) {
            expect($a)->toMatch('/^[A-Z][A-Za-z]+$/');
        }
    }
    expect(DomainEventCatalogue::has('workflow.transition.applied'))->toBeTrue();
});

test('REQ-ARC-004 every event name the code writes to the outbox is catalogued', function () {
    $missing = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app'), FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $src = file_get_contents($file->getPathname());
        // outbox writes only ($this->outbox->record / app(OutboxWriter::class)->record); AuditWriter action names are not events
        preg_match_all("/(?:outbox|OutboxWriter::class\))->record\(\s*'([a-z_]+(?:\.[a-z_]+)+)'/", $src, $m);
        foreach ($m[1] as $name) {
            if (! DomainEventCatalogue::has($name)) {
                $missing[] = $name.' ('.basename($file->getPathname()).')';
            }
        }
    }
    expect(array_unique($missing))->toBe([]);
});

test('REQ-ARC-004 emitted flag is only set on events that a producer writes today', function () {
    $emittedInCode = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app'), FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->getExtension() === 'php' && ! str_contains($file->getPathname(), 'Catalogue'.DIRECTORY_SEPARATOR.'DomainEventCatalogue')) {
            preg_match_all("/'([a-z_]+(?:\.[a-z_]+)+)'/", file_get_contents($file->getPathname()), $m);
            $emittedInCode += array_flip($m[1]);
        }
    }
    foreach (DomainEventCatalogue::all() as $name => $e) {
        if ($e->emitted) {
            expect(isset($emittedInCode[$name]))->toBeTrue("{$name} is flagged emitted but no code references it");
        }
    }
});
