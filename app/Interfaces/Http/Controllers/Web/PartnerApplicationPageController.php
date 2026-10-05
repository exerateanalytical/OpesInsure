<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Web;

use App\Application\Partners\Onboarding\PartnerApplicationService;
use App\Models\PartnerApplication;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Public partner self-service application (/partners/apply, EN/FR): anonymous by design, throttled, honeypot;
 * everything past the form is reached through the applicant's secret status link. All writes are in
 * PartnerApplicationService / IntakeReview.
 */
final class PartnerApplicationPageController
{
    private const FILE = 'file|max:5120|mimes:pdf,jpg,jpeg,png';

    public function __construct(private readonly PartnerApplicationService $service) {}

    public function form(Request $request): Response
    {
        $type = in_array($t = strtoupper((string) $request->query('type')), PartnerApplicationService::TYPES, true) ? $t : 'BROKER';

        return $this->private(response()->view('public.partners.apply', ['type' => $type, 'brokerages' => $this->service->brokerages()]));
    }

    public function submit(Request $request): RedirectResponse
    {
        if ($request->filled('website')) { // honeypot: looks like success, stores nothing
            return redirect()->route('public.partner-apply')->with('partner_apply_sent', true);
        }
        $type = (string) $request->input('type');
        $data = $request->validate([
            'type' => ['required', Rule::in(PartnerApplicationService::TYPES)],
            'legal_name' => 'required|string|min:2|max:160', 'trade_name' => 'nullable|string|max:160',
            'rccm' => 'nullable|string|max:64', 'niu' => 'required|string|max:32',
            'licence_number' => 'nullable|string|max:64', 'licence_expires_on' => 'nullable|date_format:Y-m-d',
            'independent' => 'nullable|boolean', 'brokerage_tenant_id' => 'nullable|uuid',
            'city' => 'required|string|max:120', 'address' => 'nullable|string|max:255',
            'org_phone' => 'nullable|string|max:32', 'org_email' => 'nullable|email:rfc|max:190',
            'applicant_name' => 'required|string|min:2|max:160', 'applicant_email' => 'required|email:rfc|max:190', 'applicant_phone' => 'nullable|string|max:32',
            'consent' => 'accepted',
            'doc_licence' => [$type === 'INSURER' ? 'nullable' : 'required', ...explode('|', self::FILE)],
            'doc_rccm' => [$type === 'AGENT' ? 'nullable' : 'required', ...explode('|', self::FILE)],
            'doc_id' => ['required', ...explode('|', self::FILE)],
        ]);
        $data['independent'] = $request->boolean('independent');
        $data['locale'] = app()->getLocale();

        $out = $this->service->submit($data, ['licence' => $request->file('doc_licence'), 'rccm' => $request->file('doc_rccm'), 'id' => $request->file('doc_id')], $request->ip());

        return redirect()->route('public.partner-apply.status', ['token' => $out['token']]);
    }

    public function status(string $token): Response
    {
        return $this->private(response()->view('public.partners.apply-status', ['a' => $this->find($token), 'token' => $token]));
    }

    public function verify(string $token, Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => 'required|string|max:12']);
        $ok = $this->service->verify($this->find($token), $data['code']);

        return back()->with($ok ? 'status_ok' : 'status_error', __($ok ? 'partner_apply.status.verified' : 'partner_apply.errors.wrong_code'));
    }

    public function resend(string $token): RedirectResponse
    {
        $a = $this->find($token);
        abort_unless($a->status === 'UNVERIFIED', 409);
        $this->service->sendCode($a);

        return back()->with('status_ok', __('partner_apply.status.code_resent'));
    }

    public function respond(string $token, Request $request): RedirectResponse
    {
        $data = $request->validate(['response' => 'required|string|min:5|max:5000', 'doc_extra' => ['nullable', ...explode('|', self::FILE)]]);
        $this->service->respond($this->find($token), $data['response'], ['extra' => $request->file('doc_extra')]);

        return back()->with('status_ok', __('partner_apply.status.responded'));
    }

    public function brokerage(string $token): Response
    {
        $a = $this->service->findByBrokerageToken($token);
        abort_if($a === null, 404);

        return $this->private(response()->view('public.partners.brokerage', ['a' => $a, 'token' => $token]));
    }

    public function brokerageAnswer(string $token, Request $request): RedirectResponse
    {
        $data = $request->validate(['decision' => 'required|in:confirm,decline']);
        $a = $this->service->findByBrokerageToken($token);
        abort_if($a === null, 404);
        $this->service->brokerageAnswer($a, $data['decision'] === 'confirm');

        return redirect()->route('public.partner-apply')->with('partner_apply_brokerage', $data['decision']);
    }

    private function find(string $token): PartnerApplication
    {
        $a = $this->service->findByToken($token);
        abort_if($a === null, 404);

        return $a;
    }

    /** Secret-link pages: never indexed, never leaked through the Referer header. */
    private function private(Response $response): Response
    {
        return $response->header('Referrer-Policy', 'no-referrer')->header('X-Robots-Tag', 'noindex, nofollow')->header('Cache-Control', 'no-store, private');
    }
}
