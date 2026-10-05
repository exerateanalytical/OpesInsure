<?php

declare(strict_types=1);

namespace App\Application\Underwriting\Proposal;

use App\Application\Audit\AuditWriter;
use App\Application\Identity\MobileAuthService;
use App\Application\Notifications\Adapters\NotificationAdapterRegistry;
use App\Application\Notifications\CustomerNotifier;
use App\Application\Notifications\Sms\SmsGateway;
use App\Application\Underwriting\ProposalMachine;
use App\Application\Underwriting\ProposalService;
use App\Models\PartyContact;
use App\Models\Proposal;
use App\Models\ProposalAcceptanceLink;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Web contract acceptance for customers without the app (owner decision 2026-09-30). When an agent/broker needs the
 * customer's own acceptance (assisted sale, broker premium request), the customer gets an SMS with a signed, expiring
 * (72 h), single-use link to /account/accept/{token}. There the customer proves the proposal's phone with an OTP
 * (MobileAuthService::requestPhoneProof — same code rules, demo code on demo phones), reviews the questions, the
 * declarations and the contract terms, and accepts. The acceptance is recorded through ProposalService (answer / attest /
 * declare TERMS_ACCEPTANCE / submit) as the customer's OWN user: the party's existing account, or — first acceptance of
 * a customer who never had one — an account created for that party on the phone just proven (they can sign in to the
 * app with it later). So ProposalDeclarations::acceptedByParty() holds and the payment step can proceed. The agent never
 * sees the link, and the link never goes to the agent's own number.
 */
final class ProposalAcceptanceLinks
{
    public const OTP_PURPOSE = 'PROPOSAL_ACCEPTANCE';

    private const TTL_HOURS = 72;

    /** One SMS per proposal per 10 minutes, however often the agent taps. */
    private const RESEND_MINUTES = 10;

    /** Statuses in which the customer can still accept the offered terms. */
    public const ACCEPTABLE = [...ProposalMachine::PRE_SUBMISSION, 'SUBMITTED', 'UNDER_REVIEW', 'RESUBMITTED', 'APPROVED', 'PAYMENT_PENDING'];

    public function __construct(
        private ProposalService $proposals,
        private ProposalDeclarations $declarations,
        private MobileAuthService $auth,
        private AuditWriter $audit,
    ) {}

    public function customerAccepted(Proposal $p): bool
    {
        return $this->declarations->acceptedByParty($p, 'TERMS_ACCEPTANCE') !== null;
    }

    /** The customer still has to accept, and can do it through a link. */
    public function needed(Proposal $p): bool
    {
        return in_array($p->status, self::ACCEPTABLE, true) && ! $this->customerAccepted($p);
    }

    /**
     * Sends (at most every 10 minutes) a fresh link to the proposal's phone: the party's primary phone, else
     * $fallbackPhone (the phone the agent captured on the sale). Returns status() with sent_now.
     */
    public function send(Proposal $p, ?string $fallbackPhone, User $sender): array
    {
        if (! $this->needed($p)) {
            return $this->status($p);
        }
        $phone = $this->phoneFor($p, $fallbackPhone);
        if ($phone === null || ($sender->phone_e164 !== null && $sender->phone_e164 === $phone)) {
            return ['status' => 'NO_PHONE', 'sent_now' => false] + $this->status($p);
        }

        return Cache::lock("acceptance-link:{$p->id}", 15)->block(10, function () use ($p, $phone, $sender): array {
            $recent = ProposalAcceptanceLink::where('proposal_id', $p->id)->whereNull('used_at')->where('expires_at', '>', now())
                ->where('created_at', '>', now()->subMinutes(self::RESEND_MINUTES))->where('sms_status', '!=', 'FAILED')->exists();
            if ($recent) {
                return $this->status($p);
            }
            $token = Str::random(48);
            $link = ProposalAcceptanceLink::create([
                'tenant_id' => $p->tenant_id, 'proposal_id' => $p->id, 'party_id' => $p->party_id, 'phone_e164' => $phone,
                'token_hash' => hash('sha256', $token), 'expires_at' => now()->addHours(self::TTL_HOURS), 'created_by' => $sender->id,
            ]);
            $link->update(['sms_status' => $this->sms($link, $this->url($link, $token), $p)]);
            $this->audit->record('proposal.acceptance_link.sent', 'proposal', $p->id, ['link_id' => $link->id, 'sms_status' => $link->sms_status]);

            return ['sent_now' => true] + $this->status($p);
        });
    }

    /**
     * ACCEPTED (by the customer) | LINK_SENT (an active link, SMS accepted by the provider) | SMS_FAILED | NOT_SENT.
     *
     * @return array{status: string, sent_now: bool, sent_at: ?string, expires_at: ?string, phone_masked: ?string}
     */
    public function status(Proposal $p): array
    {
        $link = ProposalAcceptanceLink::where('proposal_id', $p->id)->latest()->first();
        $status = match (true) {
            $this->customerAccepted($p) => 'ACCEPTED',
            $link === null || ! $link->usable() => 'NOT_SENT',
            in_array($link->sms_status, ['FAILED', 'NOT_CONFIGURED'], true) => 'SMS_FAILED',
            default => 'LINK_SENT',
        };

        return ['status' => $status, 'sent_now' => false, 'sent_at' => $link?->created_at?->toIso8601String(),
            'expires_at' => $link?->expires_at?->toIso8601String(), 'phone_masked' => $link ? self::mask($link->phone_e164) : null];
    }

    public function find(string $token): ?ProposalAcceptanceLink
    {
        return strlen($token) < 32 ? null : ProposalAcceptanceLink::where('token_hash', hash('sha256', $token))->first();
    }

    /** @return array{challenge_id: string, expires_in: int} */
    public function sendCode(ProposalAcceptanceLink $link, string $ip): array
    {
        $this->assertUsable($link);

        return $this->auth->requestPhoneProof($link->phone_e164, $ip, self::OTP_PURPOSE);
    }

    public function verifyCode(ProposalAcceptanceLink $link, string $challengeId, string $code, string $ip): void
    {
        $this->assertUsable($link);
        $this->auth->confirmPhoneProof($challengeId, $code, $link->phone_e164, self::OTP_PURPOSE, $ip);
        $link->update(['verified_at' => now()]);
        $this->audit->record('proposal.acceptance_link.verified', 'proposal', $link->proposal_id, ['link_id' => $link->id]);
    }

    /**
     * The customer's acceptance, after the OTP: answers (while the questions are still open), attestation, contract
     * terms, then submission of a DOCUMENTS_PENDING application. Returns the proposal and, when submission could not
     * complete (a document or KYC step outstanding), the reason — the acceptance itself is kept.
     *
     * @param  array{ip?: ?string, user_agent?: ?string}  $evidence
     * @return array{proposal: Proposal, pending: ?string}
     */
    public function accept(ProposalAcceptanceLink $link, array $answers, array $evidence): array
    {
        return Cache::lock("acceptance-link-use:{$link->id}", 30)->block(10, function () use ($link, $answers, $evidence): array {
            $link->refresh();
            $this->assertUsable($link);
            if ($link->verified_at === null) {
                throw ValidationException::withMessages(['code' => __('acceptance.verify_first')]);
            }
            $p = Proposal::findOrFail($link->proposal_id);
            if (! in_array($p->status, self::ACCEPTABLE, true)) {
                throw ValidationException::withMessages(['status' => __('acceptance.not_acceptable')]);
            }
            $customer = $this->customerUser($p, $link->phone_e164);
            $ctx = ['ip' => $evidence['ip'] ?? null, 'user_agent' => $evidence['user_agent'] ?? null, 'acceptance_link_id' => $link->id, 'verified_phone_hash' => hash('sha256', $link->phone_e164)];

            if (in_array($p->status, ['DRAFT', 'DISCLOSURES_PENDING'], true)) {
                $p = $this->proposals->answer($p, ProposalQuestions::normalise($this->proposals->questions($p), $answers), $customer);
            }
            if (! $p->attested_at && in_array($p->status, ProposalMachine::PRE_SUBMISSION, true)) {
                $p = $this->proposals->attest($p, $customer, [], 'WEB_LINK', $ctx);
            }
            $this->proposals->declare($p, 'TERMS_ACCEPTANCE', $customer, 'WEB_LINK', $ctx);
            ProposalAcceptanceLink::where('proposal_id', $p->id)->whereNull('used_at')->update(['used_at' => now()]);
            $this->audit->record('proposal.acceptance_link.accepted', 'proposal', $p->id, ['link_id' => $link->id, 'customer_user_id' => $customer->id]);

            $pending = null;
            if ($p->refresh()->status === 'DOCUMENTS_PENDING') {
                try {
                    $p = $this->proposals->submit($p, $customer);
                } catch (ValidationException $e) {
                    $pending = (string) $e->validator->errors()->first();
                }
            }

            return ['proposal' => $p->refresh(), 'pending' => $pending];
        });
    }

    public function assertUsable(ProposalAcceptanceLink $link): void
    {
        if (! $link->usable()) {
            throw ValidationException::withMessages(['link' => __('acceptance.link_expired')]);
        }
    }

    public static function mask(string $phone): string
    {
        return strlen($phone) > 6 ? substr($phone, 0, 4).str_repeat('•', max(0, strlen($phone) - 7)).substr($phone, -3) : '•••';
    }

    // ------------------------------------------------------------------ internals

    private function phoneFor(Proposal $p, ?string $fallback): ?string
    {
        $phone = PartyContact::where(['party_id' => $p->party_id, 'type' => 'PHONE'])->orderByDesc('is_primary')->value('normalized_value') ?: $fallback;

        return $phone && preg_match('/^\+[1-9]\d{7,14}$/', $phone) ? $phone : null;
    }

    private function url(ProposalAcceptanceLink $link, string $token): string
    {
        return URL::temporarySignedRoute('public.acceptance.show', $link->expires_at, ['token' => $token]);
    }

    /** SENT | FAILED | NOT_CONFIGURED | SKIPPED_DEMO — the link itself is never returned to the agent. */
    private function sms(ProposalAcceptanceLink $link, string $url, Proposal $p): string
    {
        $locale = User::where('party_id', $p->party_id)->value('locale') ?: app()->getLocale();
        $body = __('acceptance.sms', ['number' => $p->proposal_number, 'url' => $url], $locale === 'fr' ? 'fr' : 'en');
        if (MobileAuthService::isDemoPersonaPhone($link->phone_e164)) {
            Log::info('proposal.acceptance_link.demo', ['proposal_id' => $p->id, 'url' => $url]);

            return 'SKIPPED_DEMO';
        }
        if (! app(SmsGateway::class)->isConfigured() && ! CustomerNotifier::smsConfigured()) {
            Log::critical('proposal.acceptance_link.sms_not_configured', ['proposal_id' => $p->id]);

            return 'NOT_CONFIGURED';
        }
        try {
            app(NotificationAdapterRegistry::class)->for('SMS')->send($link->phone_e164, 'OpesInsure', $body, 'acceptance-link-'.$link->id);

            return 'SENT';
        } catch (Throwable $e) {
            report($e);

            return 'FAILED';
        }
    }

    /**
     * The customer's own account: the party's active user, else a new one bound to the party on the phone just proven
     * by OTP (+ a CUSTOMER membership in the proposal's tenant, so the app shows the policy later). A phone already
     * used by an account of ANOTHER customer is refused: that person signs in to the app instead.
     */
    private function customerUser(Proposal $p, string $phone): User
    {
        $own = User::where('party_id', $p->party_id)->where('status', 'ACTIVE')
            ->orderByRaw('CASE WHEN phone_e164 = ? THEN 0 ELSE 1 END', [$phone])->orderBy('created_at')->first();
        if ($own) {
            return $own;
        }
        if (User::where('phone_e164', $phone)->exists()) {
            throw ValidationException::withMessages(['code' => __('acceptance.phone_other_account')]);
        }

        return DB::transaction(function () use ($p, $phone): User {
            $user = User::create([
                'full_name' => (string) (DB::table('parties')->where('id', $p->party_id)->value('display_name') ?: $phone),
                'phone_e164' => $phone, 'party_id' => $p->party_id, 'password' => Str::random(64),
                'locale' => app()->getLocale() === 'fr' ? 'fr' : 'en', 'status' => 'ACTIVE',
            ]);
            $user->forceFill(['phone_verified_at' => now()])->save();
            TenantMembership::firstOrCreate(['tenant_id' => $p->tenant_id, 'user_id' => $user->id], ['role_code' => 'CUSTOMER', 'status' => 'ACTIVE']);
            $this->audit->record('proposal.acceptance_link.customer_account_created', 'user', $user->id, ['party_id' => $p->party_id, 'proposal_id' => $p->id]);

            return $user;
        });
    }
}
