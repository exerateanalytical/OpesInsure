<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Web;

use App\Application\Underwriting\Proposal\ProposalAcceptanceLinks;
use App\Application\Underwriting\ProposalMachine;
use App\Application\Underwriting\ProposalService;
use App\Application\WebExperiences\Money;
use App\Models\Proposal;
use App\Models\ProposalAcceptanceLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * /account/accept/{token} — the customer's web contract acceptance (ProposalAcceptanceLinks). No sign-in: the signed,
 * expiring, single-use link plus an OTP to the proposal's phone. Steps on one page: send code → verify code → review
 * questions, declarations and contract terms → accept. Verification lives in this browser session only (30 minutes).
 */
final class ProposalAcceptancePageController
{
    private const VERIFIED_MINUTES = 30;

    public function __construct(private ProposalAcceptanceLinks $links, private ProposalService $proposals) {}

    public function show(Request $request, string $token): Response
    {
        return $this->page($request, $token);
    }

    public function act(Request $request, string $token): RedirectResponse|Response
    {
        $link = $this->valid($request, $token);
        if ($link === null) {
            return $this->page($request, $token);
        }
        $key = "acceptance.{$link->id}";
        $back = redirect()->to($request->fullUrl());
        try {
            switch ((string) $request->input('intent')) {
                case 'send_code':
                    $request->session()->put("{$key}.challenge", $this->links->sendCode($link, (string) $request->ip())['challenge_id']);

                    return $back->with('acceptance_status', __('acceptance.code_sent', ['phone' => ProposalAcceptanceLinks::mask($link->phone_e164)]));
                case 'verify':
                    $data = $request->validate(['code' => 'required|digits:6']);
                    $this->links->verifyCode($link, (string) $request->session()->get("{$key}.challenge", ''), $data['code'], (string) $request->ip());
                    $request->session()->put("{$key}.verified_at", now()->timestamp);
                    $request->session()->forget("{$key}.challenge");

                    return $back;
                case 'accept':
                    if (! $this->verified($request, $link)) {
                        throw ValidationException::withMessages(['code' => __('acceptance.verify_first')]);
                    }
                    $p = $link->proposal()->firstOrFail();
                    $request->validate(['terms' => 'accepted'] + ($this->needsAttestation($p) ? ['attest' => 'accepted'] : []), [
                        'terms.accepted' => __('acceptance.terms_required'), 'attest.accepted' => __('acceptance.attest_required'),
                    ]);
                    $answers = $request->input('answers', []);
                    $result = $this->links->accept($link, is_array($answers) ? $answers : [], ['ip' => $request->ip(), 'user_agent' => $request->userAgent()]);
                    $request->session()->forget($key);

                    return $back->with('acceptance_done', ['number' => $result['proposal']->proposal_number, 'pending' => $result['pending']]);
                default:
                    return $back;
            }
        } catch (ValidationException $e) {
            return $back->withErrors($e->errors())->withInput($request->except('code'));
        }
    }

    // ------------------------------------------------------------------ internals

    /** The link behind a valid signature (the language switch adds ?lang=, ignored), or null. */
    private function valid(Request $request, string $token): ?ProposalAcceptanceLink
    {
        return $request->hasValidSignatureWhileIgnoring(['lang']) ? $this->links->find($token) : null;
    }

    private function verified(Request $request, ProposalAcceptanceLink $link): bool
    {
        $at = (int) $request->session()->get("acceptance.{$link->id}.verified_at", 0);

        return $at > 0 && $at >= now()->subMinutes(self::VERIFIED_MINUTES)->timestamp && $link->verified_at !== null;
    }

    private function needsAttestation(Proposal $p): bool
    {
        return ! $p->attested_at && in_array($p->status, ProposalMachine::PRE_SUBMISSION, true);
    }

    private function page(Request $request, string $token): Response
    {
        $done = $request->session()->get('acceptance_done');
        $link = $done ? null : $this->valid($request, $token);
        $p = $link?->proposal()->with(['offer.product', 'offer.carrier.party', 'party'])->first();
        $state = match (true) {
            $done !== null => 'done',
            $link === null => ($known = $this->links->find($token)) !== null && ! $known->usable() && $known->used_at === null ? 'expired' : 'invalid',
            $p === null => 'invalid',
            $this->links->customerAccepted($p) => 'accepted',
            $link->used_at !== null => 'used',
            $link->expires_at->isPast() => 'expired',
            in_array($p->status, ProposalMachine::TERMINAL, true) => 'closed',
            ! in_array($p->status, ProposalAcceptanceLinks::ACCEPTABLE, true) => 'blocked',
            ! $this->verified($request, $link) => 'verify',
            default => 'review',
        };
        $locale = app()->getLocale() === 'fr' ? 'fr' : 'en';

        return response()->view('public.account.accept', [
            'state' => $state, 'done' => $done, 'locale' => $locale,
            'phone' => $link ? ProposalAcceptanceLinks::mask($link->phone_e164) : null,
            'codeSent' => $link && $request->session()->has("acceptance.{$link->id}.challenge"),
            'summary' => $p && in_array($state, ['verify', 'review'], true) ? $this->summary($p, $locale) : null,
            'questions' => $p && $state === 'review' && in_array($p->status, ['DRAFT', 'DISCLOSURES_PENDING'], true) ? $this->questions($p, $locale) : [],
            'attest' => $p && $state === 'review' && $this->needsAttestation($p) ? $this->statement('DISCLOSURE_ACCURACY', $locale) : null,
            'terms' => $this->statement('TERMS_ACCEPTANCE', $locale),
        ])->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer')->header('X-Robots-Tag', 'noindex, nofollow');
    }

    private function summary(Proposal $p, string $locale): array
    {
        $t = $p->terms_snapshot ?? [];
        $cur = (string) ($t['currency'] ?? 'XAF');
        $carrier = $p->offer?->carrier;
        $tr = fn ($v) => is_array($v) ? ($v[$locale] ?? $v['en'] ?? reset($v) ?: null) : $v;

        return [
            'number' => $p->proposal_number, 'customer' => $p->party?->display_name,
            'product' => $tr($p->offer?->product?->name), 'carrier' => $carrier ? ($carrier->brand_short_name ?: $carrier->trade_name ?: $carrier->short_name ?: $carrier->legal_name ?: $carrier->party?->display_name) : null,
            'rows' => array_values(array_filter([
                [__('acceptance.premium'), $t['premium_minor'] ?? null], [__('acceptance.tax'), $t['tax_minor'] ?? null], [__('acceptance.fees'), $t['fee_minor'] ?? null],
            ], fn ($r) => (int) ($r[1] ?? 0) > 0)),
            'total' => Money::display(isset($t['total_minor']) ? (int) $t['total_minor'] : null, $cur),
            'money' => fn ($m) => Money::display((int) $m, $cur),
            'coverages' => collect(($t['coverage_snapshot'] ?? [])['coverages'] ?? [])->map(fn ($c) => [
                'name' => $tr($c['name'] ?? null) ?: ucwords(strtolower(str_replace('_', ' ', (string) ($c['code'] ?? '')))),
                'limit' => ! empty($c['limit_minor']) ? Money::display((int) $c['limit_minor'], $cur) : null, 'optional' => (bool) ($c['optional'] ?? false),
            ])->filter(fn ($c) => $c['name'] !== '')->values()->all(),
        ];
    }

    private function questions(Proposal $p, string $locale): array
    {
        $answers = old('answers', $p->disclosures ?? []);

        return collect($this->proposals->questions($p))->map(function (array $q) use ($locale, $answers) {
            $label = $q['label'] ?? $q['code'];
            $opts = collect($q['options'] ?? [])->map(fn ($o) => is_array($o)
                ? ['value' => (string) ($o['value'] ?? $o['code'] ?? ''), 'label' => (string) (is_array($o['label'] ?? null) ? ($o['label'][$locale] ?? reset($o['label'])) : ($o['label'] ?? $o['value'] ?? ''))]
                : ['value' => (string) $o, 'label' => (string) $o])->all();
            $v = is_array($answers) ? ($answers[$q['code']] ?? null) : null;

            return ['code' => $q['code'], 'label' => (string) (is_array($label) ? ($label[$locale] ?? $label['en'] ?? reset($label)) : $label),
                'type' => strtolower((string) ($q['type'] ?? 'boolean')), 'required' => (bool) ($q['required'] ?? true), 'options' => $opts,
                'value' => is_bool($v) ? ($v ? 'true' : 'false') : (is_scalar($v) ? (string) $v : '')];
        })->all();
    }

    private function statement(string $code, string $locale): ?string
    {
        $s = config("proposals.declarations.{$code}.statement");

        return is_array($s) ? ($s[$locale] ?? $s['en'] ?? null) : null;
    }
}
