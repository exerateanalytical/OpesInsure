<?php

declare(strict_types=1);

use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use App\Application\Notifications\LaunchNotificationRouter;
use App\Application\Notifications\NotificationCatalog;
use App\Application\Notifications\NotificationTemplateRenderer;
use App\Mail\NotificationMail;
use App\Models\Claim;
use App\Models\KycSubmission;
use App\Models\NotificationTemplate;
use App\Models\Party;
use App\Models\Policy;
use App\Models\User;
use App\Models\UserNotification;
use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

/*
 * S8 launch notification coverage: every launch flow's domain event reaches
 * the right user, as the right catalog code, with channel templates (in-app,
 * email, SMS) in EN and FR, rendered in the recipient's language.
 */

function s8Fixture(string $locale = 'fr'): array
{
    $f = makeMobileCustomerFixture('+2376712'.random_int(10000, 99999));
    $f['user']->forceFill(['locale' => $locale, 'email' => 'cust'.Str::random(6).'@example.test', 'full_name' => 'Awa Nkemdirim'])->save();
    (new NotificationTemplateSeeder)->run();
    Mail::fake();

    return $f;
}

function s8Codes(User $user): array
{
    return UserNotification::where('user_id', $user->id)->pluck('code')->all();
}

function s8MailSubjects(): array
{
    return Mail::queued(NotificationMail::class)->map(fn (NotificationMail $m) => $m->envelope()->subject)->all();
}

function s8Frtitle(string $code, array $params = []): string
{
    return NotificationCatalog::render($code, $params, 'fr')['title'];
}

function s8Policy(array $f): Policy
{
    return Policy::create(['tenant_id' => $f['tenant']->id, 'proposal_id' => $f['proposal']->id, 'carrier_id' => $f['carrier']->id, 'party_id' => $f['party']->id,
        'policy_number' => 'POL-S8-'.Str::random(5), 'status' => 'ACTIVE', 'coverage_starts_at' => now(), 'coverage_ends_at' => now()->addYear(), 'terms_snapshot' => []]);
}

function s8Claim(array $f): Claim
{
    return Claim::create(['tenant_id' => $f['tenant']->id, 'policy_id' => s8Policy($f)->id, 'claimant_party_id' => $f['party']->id, 'claim_number' => 'CLM-S8-'.Str::random(5),
        'status' => 'DRAFT', 'loss_occurred_at' => now()->subDay(), 'loss_details' => ['description' => 'x'], 'currency' => 'XAF']);
}

it('seeds IN_APP, EMAIL and SMS templates in EN and FR for every notification code, idempotently', function () {
    (new NotificationTemplateSeeder)->run();
    $count = NotificationTemplate::count();
    (new NotificationTemplateSeeder)->run();
    expect(NotificationTemplate::count())->toBe($count);

    $codes = collect(Lang::get('customer_notifications', [], 'en'))->keys()->reject(fn ($c) => str_starts_with($c, '_'));
    foreach ($codes as $code) {
        foreach (['en', 'fr'] as $locale) {
            foreach (NotificationTemplateRenderer::CHANNELS as $channel) {
                expect(NotificationTemplate::whereNull('tenant_id')->where(compact('code', 'locale', 'channel'))->where('status', 'ACTIVE')->exists())
                    ->toBeTrue("{$code}/{$locale}/{$channel}");
            }
        }
    }
});

it('keeps every SMS within one GSM-7 segment and free of personal data beyond a reference', function () {
    (new NotificationTemplateSeeder)->run();
    $long = ['policy' => 'POL-2026-000000123', 'claim' => 'CLM-2026-000000123', 'reference' => 'REF-2026-00000123', 'request' => 'REQ-2026-0000123',
        'days' => 90, 'count' => 12, 'product' => 'Motor Third Party Comprehensive', 'device' => 'Samsung Galaxy A54', 'code' => str_repeat('A', 64), 'hours' => 72];
    $renderer = app(NotificationTemplateRenderer::class);
    foreach (NotificationTemplate::whereNull('tenant_id')->where('channel', 'SMS')->whereIn('code', array_keys(Lang::get('customer_notifications', [], 'en')))->get() as $t) {
        $sms = $renderer->render($t->code, 'SMS', $t->locale, null, $long)['body'];
        expect(mb_strlen($sms))->toBeLessThanOrEqual(160, "{$t->code}/{$t->locale}: {$sms}")
            ->and(NotificationTemplateRenderer::isGsm7($sms))->toBeTrue("{$t->code}/{$t->locale} not GSM-7: {$sms}")
            ->and($t->body)->not->toContain('{{first_name}}')->not->toContain('{{amount}}')->not->toContain('{{device}}');
    }
});

it('prefers a tenant override over the platform template', function () {
    $f = s8Fixture('en');
    NotificationTemplate::create(['tenant_id' => $f['tenant']->id, 'code' => 'kyc_approved', 'locale' => 'en', 'channel' => 'EMAIL', 'version' => 2, 'status' => 'ACTIVE',
        'purpose' => 'TRANSACTIONAL', 'subject' => 'Tenant says: verified', 'body' => 'Hi {{first_name}}', 'required_variables' => []]);
    $s = KycSubmission::create(['tenant_id' => $f['tenant']->id, 'party_id' => $f['party']->id, 'status' => 'APPROVED']);

    app(OutboxWriter::class)->record('kyc_submission.approved', 'kyc_submission', $s->id, ['kyc_submission_id' => $s->id, 'party_id' => $f['party']->id]);

    Mail::assertQueued(NotificationMail::class, fn (NotificationMail $m) => $m->envelope()->subject === 'Tenant says: verified');
});

it('notifies KYC decisions and remediation in the customer language', function (string $event, string $code) {
    $f = s8Fixture('fr');
    $s = KycSubmission::create(['tenant_id' => $f['tenant']->id, 'party_id' => $f['party']->id, 'status' => 'APPROVED']);

    app(OutboxWriter::class)->record($event, 'kyc_submission', $s->id, ['kyc_submission_id' => $s->id, 'party_id' => $f['party']->id]);

    expect(s8Codes($f['user']))->toContain($code)
        ->and(s8MailSubjects())->toContain(s8Frtitle($code));
})->with([
    ['kyc_submission.approved', 'kyc_approved'],
    ['kyc_submission.rejected', 'kyc_rejected'],
    ['kyc_submission.information_requested', 'kyc_information_requested'],
    ['kyc_submission.remediation_requested', 'kyc_remediation_requested'],
    ['kyc_submission.expired', 'kyc_expired'],
]);

it('notifies agent payment requests, proposal information requests and conditional offers', function () {
    $f = s8Fixture('fr');
    app(AuditWriter::class)->record('agent.sale.payment_requested', 'quote', $f['quote']->id, []);
    app(AuditWriter::class)->record('proposal.information.requested', 'proposal', $f['proposal']->id, ['items' => []]);
    app(OutboxWriter::class)->record('underwriting.decided', 'proposal', $f['proposal']->id, ['proposal_id' => $f['proposal']->id, 'decision' => 'APPROVED', 'outcome' => 'CONDITIONAL']);
    app(OutboxWriter::class)->record('underwriting.decided', 'proposal', $f['proposal']->id, ['proposal_id' => $f['proposal']->id, 'decision' => 'APPROVED', 'outcome' => 'APPROVED']);

    expect(s8Codes($f['user']))->toEqualCanonicalizing(['agent_payment_requested', 'proposal_information_needed', 'proposal_conditional_offer'])
        ->and(s8MailSubjects())->toContain(s8Frtitle('agent_payment_requested'), s8Frtitle('proposal_conditional_offer'));
});

it('notifies an issued endorsement once even though it is both audited and published', function () {
    $f = s8Fixture('en');
    $policy = s8Policy($f);
    app(AuditWriter::class)->record('policy.endorsement.issued', 'policy_transaction', (string) Str::uuid(), ['policy_id' => $policy->id]);
    app(OutboxWriter::class)->record('policy.endorsement.issued', 'policy', $policy->id, ['policy_id' => $policy->id]);
    app(OutboxWriter::class)->record('policy.endorsement.issued', 'policy', $policy->id, ['policy_id' => $policy->id]);

    expect(UserNotification::where('user_id', $f['user']->id)->where('code', 'endorsement_issued')->count())->toBe(1)
        ->and(UserNotification::where('user_id', $f['user']->id)->where('code', 'endorsement_issued')->value('body'))->toContain($policy->policy_number);
    Mail::assertQueuedCount(1);
});

it('notifies the customer and the appointed expert on assignment, and the customer on inspection and settlement steps', function () {
    $f = s8Fixture('fr');
    $claim = s8Claim($f);
    $expertParty = Party::create(['type' => 'INDIVIDUAL', 'display_name' => 'Expert S8', 'status' => 'ACTIVE']);
    $expert = User::create(['full_name' => 'Jean Expert', 'phone_e164' => '+237699000111', 'party_id' => $expertParty->id, 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
    $partnerId = (string) Str::uuid();
    DB::table('partners')->insert(['id' => $partnerId, 'party_id' => $expertParty->id, 'type' => 'PROVIDER', 'created_at' => now(), 'updated_at' => now()]);
    $providerId = (string) Str::uuid();
    DB::table('provider_profiles')->insert(['id' => $providerId, 'partner_id' => $partnerId, 'party_id' => $expertParty->id, 'category' => 'EXPERT', 'provider_type_code' => 'MOTOR_EXPERT', 'created_at' => now(), 'updated_at' => now()]);

    $data = ['claim_id' => $claim->id, 'assignment_id' => (string) Str::uuid(), 'provider_id' => $providerId];
    app(OutboxWriter::class)->record('claim.expert.assigned', 'claim', $claim->id, $data);
    app(OutboxWriter::class)->record('claim.expert.inspection_scheduled', 'claim', $claim->id, $data);
    app(OutboxWriter::class)->record('claim.settlement.offered', 'claim', $claim->id, ['claim_settlement_id' => 'x']);
    app(OutboxWriter::class)->record('claim.settlement.discharge_requested', 'claim', $claim->id, ['claim_settlement_id' => 'x']);
    app(OutboxWriter::class)->record('claim.settlement.paid', 'claim', $claim->id, ['claim_settlement_id' => 'x']);

    expect(s8Codes($f['user']))->toContain('claim_expert_assigned', 'claim_inspection_scheduled', 'settlement_offered', 'settlement_discharge_requested', 'claim_paid')
        ->and(s8Codes($expert))->toBe(['expert_assignment_new'])
        ->and(s8MailSubjects())->toContain(s8Frtitle('settlement_offered'), s8Frtitle('claim_inspection_scheduled'));
});

it('notifies refunds to the payer', function () {
    $f = s8Fixture('en');
    $intent = DB::table('payment_intents')->insertGetId(['id' => $iid = (string) Str::uuid(), 'tenant_id' => $f['tenant']->id, 'proposal_id' => $f['proposal']->id, 'provider' => 'mtn_momo', 'payer_phone_e164' => '+237670000001',
        'provider_reference' => Str::random(10), 'amount_minor' => 1000, 'currency' => 'XAF', 'status' => 'SUCCEEDED', 'idempotency_key' => Str::uuid(), 'created_at' => now(), 'updated_at' => now()], 'id');
    DB::table('refunds')->insert(['id' => $rid = (string) Str::uuid(), 'tenant_id' => $f['tenant']->id, 'payment_intent_id' => $iid, 'refund_number' => 'RFD-S8-1', 'amount_minor' => 1000,
        'currency' => 'XAF', 'reason_code' => 'CANCELLATION', 'requested_by' => $f['user']->id, 'created_at' => now(), 'updated_at' => now()]);

    app(AuditWriter::class)->record('refund.requested', 'refund', $rid, ['amount_minor' => 1000]);
    app(OutboxWriter::class)->record('refund.approved', 'refund', $rid, ['refund_id' => $rid]);

    expect(s8Codes($f['user']))->toEqualCanonicalizing(['refund_requested', 'refund_approved'])
        ->and(s8MailSubjects())->toContain('Refund approved');
});

it('notifies commission statements and payouts to the partner, and bordereau decisions to the preparer', function () {
    $f = s8Fixture('fr');
    $partnerId = (string) Str::uuid();
    DB::table('partners')->insert(['id' => $partnerId, 'party_id' => $f['party']->id, 'type' => 'AGENT', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('partner_statements')->insert(['id' => $sid = (string) Str::uuid(), 'tenant_id' => $f['tenant']->id, 'partner_id' => $partnerId, 'statement_number' => 'ST-S8-1',
        'period_start' => now()->startOfMonth()->toDateString(), 'period_end' => now()->toDateString(), 'currency' => 'XAF', 'content_hash' => Str::random(64),
        'idempotency_key' => Str::uuid(), 'prepared_by' => $f['user']->id, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('partner_payout_requests')->insert(['id' => $pid = (string) Str::uuid(), 'partner_id' => $partnerId, 'payout_number' => 'PO-S8-1', 'amount_minor' => 5000, 'currency' => 'XAF',
        'destination_type' => 'MOMO', 'destination_encrypted' => 'x', 'requested_by' => $f['user']->id, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('bordereaux')->insert(['id' => $bid = (string) Str::uuid(), 'tenant_id' => $f['tenant']->id, 'carrier_id' => $f['carrier']->id, 'type' => 'PREMIUM', 'bordereau_number' => 'BDX-S8-1',
        'period_start' => now()->startOfMonth()->toDateString(), 'period_end' => now()->toDateString(), 'prepared_by' => $f['user']->id, 'created_at' => now(), 'updated_at' => now()]);

    app(OutboxWriter::class)->record('partner.statement.approved', 'partner_statement', $sid, ['statement_id' => $sid]);
    app(OutboxWriter::class)->record('partner.payout.paid', 'partner_payout_request', $pid, ['payout_id' => $pid]);
    app(OutboxWriter::class)->record('partner.payout.reversed', 'partner_payout_request', $pid, ['payout_id' => $pid]);
    app(OutboxWriter::class)->record('bordereau.rejected', 'bordereau', $bid, ['bordereau_id' => $bid]);

    expect(s8Codes($f['user']))->toEqualCanonicalizing(['commission_statement_ready', 'payout_paid', 'payout_reversed', 'bordereau_rejected'])
        ->and(s8MailSubjects())->toContain(s8Frtitle('payout_paid'), s8Frtitle('bordereau_rejected'));
});

it('notifies complaint lifecycle steps to the complainant', function () {
    $f = s8Fixture('fr');
    $complaint = (object) ['id' => (string) Str::uuid(), 'tenant_id' => $f['tenant']->id, 'party_id' => $f['party']->id, 'complaint_number' => 'CPL-S8-1'];
    $router = app(LaunchNotificationRouter::class);
    foreach (['RECEIVED', 'ACKNOWLEDGED', 'CLASSIFIED', 'COMMUNICATED', 'ESCALATED_CIMA', 'CLOSED'] as $status) {
        $router->complaintStatus($complaint, $status);
    }

    expect(s8Codes($f['user']))->toEqualCanonicalizing(['complaint_received', 'complaint_acknowledged', 'complaint_resolution', 'complaint_escalated', 'complaint_closed'])
        ->and(s8MailSubjects())->toContain(s8Frtitle('complaint_closed'));
});

it('sends staff invitations to the invited email in the inviter language', function () {
    (new NotificationTemplateSeeder)->run();
    Mail::fake();
    expect(app(LaunchNotificationRouter::class)->staffInvitation('new.staff@example.test', null, str_repeat('t', 64), 72, null, 'fr'))->toBe('EMAIL');
    Mail::assertQueued(NotificationMail::class, fn (NotificationMail $m) => $m->hasTo('new.staff@example.test') && $m->envelope()->subject === s8Frtitle('staff_invitation'));
});

it('honours a party opt-out of the email channel but keeps the inbox row', function () {
    $f = s8Fixture('en');
    DB::table('communication_preferences')->insert(['id' => (string) Str::uuid(), 'party_id' => $f['party']->id, 'purpose' => 'TRANSACTIONAL', 'channel' => 'EMAIL',
        'enabled' => false, 'source' => 'CUSTOMER', 'changed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $s = KycSubmission::create(['tenant_id' => $f['tenant']->id, 'party_id' => $f['party']->id, 'status' => 'APPROVED']);

    app(OutboxWriter::class)->record('kyc_submission.approved', 'kyc_submission', $s->id, ['kyc_submission_id' => $s->id]);

    expect(s8Codes($f['user']))->toBe(['kyc_approved']);
    Mail::assertNothingQueued();
});

it('schedules renewal reminders at 90/60/30/15/7 days (plus the day before)', function () {
    expect(config('lifecycle.expiry_reminder_days'))->toBe([90, 60, 30, 15, 7, 1]);
});

it('deep-links complaint notifications to a support screen the app has (never /support/complaints/{id})', function () {
    $f = s8Fixture('en');
    $router = app(LaunchNotificationRouter::class);
    $ticket = (string) Str::uuid();
    $router->complaintStatus((object) ['id' => (string) Str::uuid(), 'tenant_id' => $f['tenant']->id, 'party_id' => $f['party']->id, 'complaint_number' => 'CPL-S8-2', 'support_ticket_id' => $ticket], 'RECEIVED');
    $router->complaintStatus((object) ['id' => (string) Str::uuid(), 'tenant_id' => $f['tenant']->id, 'party_id' => $f['party']->id, 'complaint_number' => 'CPL-S8-3'], 'CLOSED');

    $paths = UserNotification::where('user_id', $f['user']->id)->where('type', 'COMPLAINT')->pluck('path')->all();
    expect($paths)->toContain("/support/{$ticket}", '/support')
        ->and(collect($paths)->filter(fn ($p) => str_contains((string) $p, '/complaints/'))->all())->toBe([]);
});
