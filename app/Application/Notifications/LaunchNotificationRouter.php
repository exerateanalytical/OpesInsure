<?php

declare(strict_types=1);

namespace App\Application\Notifications;

use App\Application\Notifications\Adapters\TwilioSmsAdapter;
use App\Mail\NotificationMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * S8: turns domain events into user notifications for the flows that had
 * none (KYC decisions, complaints, agent payment requests, conditional
 * offers, endorsements, expert assignments / inspections, settlement offers,
 * refunds, commission statements, payouts, bordereau decisions).
 *
 * Fed by OutboxWriter::record() and AuditWriter::record() — every producer
 * already writes one or both, so no service has to remember to notify. An
 * event recorded on both paths is notified once: UserNotification::notify()
 * de-duplicates the same (type, path, title) inside ten minutes, and push /
 * SMS / email only go out for a freshly created row.
 *
 * Flows notified elsewhere (not repeated here): claim status changes, proposal
 * status, payment failure/expiry (LifecycleNotificationProducer); payment
 * success and issuance (PaymentIssuanceTrigger / PolicyIssuanceService);
 * cancellation (CancellationService); renewal reminders 90/60/30/15/7/1
 * (policies:notify-expiry); upload scan results (DocumentScanQueue);
 * security alerts (SecurityAlerts); staff invitations (InvitationService).
 *
 * Never throws and never re-enters itself.
 */
final class LaunchNotificationRouter
{
    private static bool $routing = false;

    public function __construct(private CustomerNotifier $notifier) {}

    /** Static entry for the writers: fail-safe, re-entrancy-safe. */
    public static function observe(string $event, ?string $subjectType, ?string $subjectId, array $data = []): void
    {
        if (self::$routing || ! self::handles($event)) {
            return;
        }
        self::$routing = true;
        try {
            app(self::class)->route($event, $subjectType, $subjectId, $data);
        } catch (Throwable $e) {
            report($e);
        } finally {
            self::$routing = false;
        }
    }

    public static function handles(string $event): bool
    {
        return isset(self::EVENTS[$event]) || str_starts_with($event, 'partner.payout.');
    }

    /** event => handler method; the catalog code(s) are chosen inside. */
    private const EVENTS = [
        'complaint.submitted' => 'complaint',
        'kyc_submission.approved' => 'kyc', 'kyc_submission.rejected' => 'kyc', 'kyc_submission.information_requested' => 'kyc',
        'kyc_submission.remediation_requested' => 'kyc', 'kyc_submission.expired' => 'kyc',
        'agent.sale.payment_requested' => 'agentPaymentRequested',
        'proposal.information.requested' => 'proposalInformation',
        'underwriting.decided' => 'underwritingDecided',
        'policy.endorsement.issued' => 'endorsementIssued',
        'claim.expert.assigned' => 'expertAssigned',
        'claim.expert.inspection_scheduled' => 'inspectionScheduled', 'claim.inspection.rescheduled' => 'inspectionScheduled',
        'claim.settlement.offered' => 'settlement', 'claim.settlement.discharge_requested' => 'settlement', 'claim.settlement.paid' => 'settlement',
        'refund.requested' => 'refund', 'refund.approved' => 'refund',
        'partner.statement.approved' => 'statement', 'commission.statement.dispute_resolved' => 'statement',
        'bordereau.approved' => 'bordereau', 'bordereau.acknowledged' => 'bordereau', 'bordereau.rejected' => 'bordereau',
    ];

    public function route(string $event, ?string $subjectType, ?string $subjectId, array $data): void
    {
        $method = self::EVENTS[$event] ?? 'payout';
        $this->{$method}($event, $subjectType, $subjectId, $data);
    }

    /** Complaint lifecycle (ComplaintService calls this for status steps; complaint.submitted arrives via the outbox). */
    public function complaintStatus(object $complaint, string $status): void
    {
        $code = match (true) {
            $status === 'RECEIVED' => 'complaint_received',
            $status === 'ACKNOWLEDGED' => 'complaint_acknowledged',
            $status === 'WAITING_CUSTOMER' => 'complaint_information_needed',
            $status === 'COMMUNICATED' => 'complaint_resolution',
            str_starts_with($status, 'ESCALATED') => 'complaint_escalated',
            $status === 'CLOSED' => 'complaint_closed',
            default => null,
        };
        if ($code && $complaint->party_id) {
            // The app's complaint register (app/complaints/[id].tsx, GET mobile/complaints/{id}) opens the complaint itself.
            $this->notifier->toParty($complaint->party_id, $complaint->tenant_id, 'COMPLAINT',
                ...NotificationCatalog::message($code, ['reference' => $complaint->complaint_number]), path: "/complaints/{$complaint->id}");
        }
    }

    private function complaint(string $event, ?string $type, ?string $id, array $data): void
    {
        $row = isset($data['complaint_id']) ? DB::table('complaints')->where('id', $data['complaint_id'])->first() : null;
        if ($row) {
            $this->complaintStatus($row, 'RECEIVED');
        }
    }

    private function kyc(string $event, ?string $type, ?string $id, array $data): void
    {
        $s = DB::table('kyc_submissions')->where('id', $data['kyc_submission_id'] ?? $id)->first();
        if (! $s) {
            return;
        }
        [$code, $severity] = match ($event) {
            'kyc_submission.approved' => ['kyc_approved', 'SUCCESS'],
            'kyc_submission.rejected' => ['kyc_rejected', 'ERROR'],
            'kyc_submission.information_requested' => ['kyc_information_requested', 'WARNING'],
            'kyc_submission.remediation_requested' => ['kyc_remediation_requested', 'WARNING'],
            default => ['kyc_expired', 'WARNING'],
        };
        $this->notifier->toParty($s->party_id, $s->tenant_id, 'KYC', ...NotificationCatalog::message($code), severity: $severity, path: "/kyc/{$s->id}");
    }

    private function agentPaymentRequested(string $event, ?string $type, ?string $id, array $data): void
    {
        $q = DB::table('quotes')->where('id', $id)->first();
        if ($q) {
            $this->notifier->toParty($q->party_id, $q->tenant_id, 'PAYMENT',
                ...NotificationCatalog::message('agent_payment_requested', ['reference' => $q->quote_number ?? null]), severity: 'WARNING', path: "/quotes/{$q->id}", forceSms: true);
        }
    }

    private function proposalInformation(string $event, ?string $type, ?string $id, array $data): void
    {
        $p = DB::table('proposals')->where('id', $id)->first();
        if ($p) {
            $this->notifier->toParty($p->party_id, $p->tenant_id, 'PROPOSAL', ...NotificationCatalog::message('proposal_information_needed'), severity: 'WARNING', path: "/proposals/{$p->id}");
        }
    }

    private function underwritingDecided(string $event, ?string $type, ?string $id, array $data): void
    {
        // Plain approve / counter-offer / decline are notified from the proposal status; only the conditional acceptance adds something.
        if (($data['outcome'] ?? null) !== 'CONDITIONAL') {
            return;
        }
        $p = DB::table('proposals')->where('id', $data['proposal_id'] ?? $id)->first();
        if ($p) {
            $this->notifier->toParty($p->party_id, $p->tenant_id, 'PROPOSAL', ...NotificationCatalog::message('proposal_conditional_offer'), severity: 'WARNING', path: "/proposals/{$p->id}");
        }
    }

    private function endorsementIssued(string $event, ?string $type, ?string $id, array $data): void
    {
        if ($type !== 'policy') {
            return; // the audit row is keyed on the transaction; the outbox one on the policy
        }
        $policy = DB::table('policies')->where('id', $id)->first();
        if ($policy) {
            $this->notifier->toParty($policy->party_id, $policy->tenant_id, 'ENDORSEMENT',
                ...NotificationCatalog::message('endorsement_issued', ['policy' => $policy->policy_number]), severity: 'SUCCESS', path: "/policy/{$policy->id}");
        }
    }

    private function expertAssigned(string $event, ?string $type, ?string $id, array $data): void
    {
        $claim = $this->claim($data['claim_id'] ?? $id);
        if (! $claim) {
            return;
        }
        $this->notifier->toParty($claim->party, $claim->tenant_id, 'CLAIM',
            ...NotificationCatalog::message('claim_expert_assigned', ['claim' => $claim->claim_number]), path: "/claim/{$claim->id}");
        $provider = DB::table('provider_profiles')->where('id', $data['provider_id'] ?? null)->first();
        if ($provider?->party_id) {
            foreach ($this->usersActingFor($provider->party_id) as $user) {
                $this->notifier->toUser($user, $claim->tenant_id, 'ASSIGNMENT',
                    ...NotificationCatalog::message('expert_assignment_new', ['claim' => $claim->claim_number]), severity: 'WARNING',
                    path: '/adjuster/assignments/'.($data['assignment_id'] ?? $claim->id), forceSms: true);
            }
        }
    }

    private function inspectionScheduled(string $event, ?string $type, ?string $id, array $data): void
    {
        $claim = $this->claim($data['claim_id'] ?? $id);
        if ($claim) {
            $this->notifier->toParty($claim->party, $claim->tenant_id, 'CLAIM',
                ...NotificationCatalog::message('claim_inspection_scheduled', ['claim' => $claim->claim_number]), path: "/claim/{$claim->id}", forceSms: true);
        }
    }

    private function settlement(string $event, ?string $type, ?string $id, array $data): void
    {
        $claim = $this->claim($id);
        if (! $claim) {
            return;
        }
        // "paid" reuses claim_paid so it merges with the claim-status notification when both fire.
        [$code, $severity] = match ($event) {
            'claim.settlement.offered' => ['settlement_offered', 'WARNING'],
            'claim.settlement.discharge_requested' => ['settlement_discharge_requested', 'WARNING'],
            default => ['claim_paid', 'SUCCESS'],
        };
        $this->notifier->toParty($claim->party, $claim->tenant_id, 'CLAIM',
            ...NotificationCatalog::message($code, ['claim' => $claim->claim_number]), severity: $severity, path: "/claim/{$claim->id}", forceSms: true);
    }

    private function refund(string $event, ?string $type, ?string $id, array $data): void
    {
        $r = DB::table('refunds as r')->leftJoin('payment_intents as i', 'i.id', '=', 'r.payment_intent_id')->leftJoin('proposals as p', 'p.id', '=', 'i.proposal_id')
            ->where('r.id', $data['refund_id'] ?? $id)->first(['r.id', 'r.refund_number', 'r.tenant_id', 'p.party_id']);
        if ($r?->party_id) {
            $this->notifier->toParty($r->party_id, $r->tenant_id, 'PAYMENT',
                ...NotificationCatalog::message($event === 'refund.approved' ? 'refund_approved' : 'refund_requested', ['reference' => $r->refund_number]),
                severity: $event === 'refund.approved' ? 'SUCCESS' : 'INFO', path: "/refunds/{$r->id}");
        }
    }

    private function statement(string $event, ?string $type, ?string $id, array $data): void
    {
        $s = DB::table('partner_statements as s')->join('partners as p', 'p.id', '=', 's.partner_id')->where('s.id', $data['statement_id'] ?? $id)
            ->first(['s.id', 's.statement_number', 's.tenant_id', 'p.party_id']);
        if ($s?->party_id) {
            $this->notifier->toParty($s->party_id, $s->tenant_id, 'COMMISSION',
                ...NotificationCatalog::message($event === 'partner.statement.approved' ? 'commission_statement_ready' : 'commission_statement_dispute_resolved', ['reference' => $s->statement_number]),
                path: "/partner/statements/{$s->id}");
        }
    }

    private function payout(string $event, ?string $type, ?string $id, array $data): void
    {
        $code = ['partner.payout.approved' => 'payout_approved', 'partner.payout.paid' => 'payout_paid', 'partner.payout.reversed' => 'payout_reversed'][$event] ?? null;
        $p = $code ? DB::table('partner_payout_requests as r')->join('partners as p', 'p.id', '=', 'r.partner_id')->where('r.id', $data['payout_id'] ?? $id)
            ->first(['r.id', 'r.payout_number', 'p.party_id']) : null;
        if ($p?->party_id) {
            $this->notifier->toParty($p->party_id, null, 'COMMISSION',
                ...NotificationCatalog::message($code, ['reference' => $p->payout_number]), severity: $code === 'payout_reversed' ? 'ERROR' : 'SUCCESS',
                path: "/partner/payouts/{$p->id}", forceSms: $code !== 'payout_approved');
        }
    }

    private function bordereau(string $event, ?string $type, ?string $id, array $data): void
    {
        $b = DB::table('bordereaux')->where('id', $data['bordereau_id'] ?? $id)->first();
        $preparer = $b?->prepared_by ? User::find($b->prepared_by) : null;
        if ($preparer) {
            $code = 'bordereau_'.substr($event, strlen('bordereau.'));
            $this->notifier->toUser($preparer, $b->tenant_id, 'BORDEREAU', ...NotificationCatalog::message($code, ['reference' => $b->bordereau_number]),
                severity: $code === 'bordereau_rejected' ? 'ERROR' : 'SUCCESS', path: "/bordereaux/{$b->id}");
        }
    }

    /**
     * Staff invitation: the invitee has no account yet, so it goes straight to the invited email
     * (or SMS for a phone invitation) in the inviter's language. Returns the channel used, or null.
     */
    public function staffInvitation(?string $email, ?string $phone, string $token, int $hours, ?string $tenantId, ?string $locale): ?string
    {
        try {
            $locale = NotificationCatalog::locale($locale);
            $params = ['code' => $token, 'hours' => $hours];
            $templates = app(NotificationTemplateRenderer::class);
            $fallback = NotificationCatalog::render('staff_invitation', $params, $locale);
            if ($email) {
                $t = $templates->render('staff_invitation', 'EMAIL', $locale, $tenantId, $params);
                Mail::to($email)->queue((new NotificationMail((string) ($t['subject'] ?? $fallback['title']), $t['body'] ?? $fallback['body']))->afterCommit());

                return 'EMAIL';
            }
            if ($phone && CustomerNotifier::smsConfigured()) {
                $t = $templates->render('staff_invitation', 'SMS', $locale, $tenantId, $params);
                app(TwilioSmsAdapter::class)->send($phone, $fallback['title'], $t['body'] ?? NotificationTemplateRenderer::gsm($fallback['body']), 'invite-'.substr(hash('sha256', $token), 0, 32));

                return 'SMS';
            }
        } catch (Throwable $e) {
            report($e);
        }

        return null;
    }

    /** @return object{id: string, tenant_id: string, claim_number: string, party: ?string}|null */
    private function claim(?string $id): ?object
    {
        $c = $id ? DB::table('claims as c')->leftJoin('policies as p', 'p.id', '=', 'c.policy_id')->where('c.id', $id)
            ->first(['c.id', 'c.tenant_id', 'c.claim_number', 'c.claimant_party_id', 'p.party_id']) : null;

        return $c ? (object) ['id' => $c->id, 'tenant_id' => $c->tenant_id, 'claim_number' => $c->claim_number, 'party' => $c->claimant_party_id ?? $c->party_id] : null;
    }

    /** Users of a provider party: the party itself and its ACTIVE employees. */
    private function usersActingFor(string $partyId)
    {
        $employees = DB::table('party_relationships')->where('to_party_id', $partyId)->where('type', 'EMPLOYED_BY')->where('status', 'ACTIVE')->pluck('from_party_id')->all();

        return User::whereIn('party_id', array_merge([$partyId], $employees))->where('status', '!=', 'DISABLED')->get();
    }
}
