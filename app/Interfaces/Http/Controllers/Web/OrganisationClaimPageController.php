<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Web;

use App\Application\Partners\Onboarding\OrganisationClaimService;
use App\Application\Partners\Onboarding\PublicIntake;
use App\Models\OrganisationClaim;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * "Claim this organisation" pages (/organisations/claim/{insurer|broker}/{id}), linked from the public directory.
 * Anonymous by design and throttled; the claimant never chooses where the verification code goes (it goes to the
 * official contact on record). All writes are in OrganisationClaimService / IntakeReview.
 */
final class OrganisationClaimPageController
{
    private const FILE = ['file', 'max:5120', 'mimes:pdf,jpg,jpeg,png'];

    public function __construct(private readonly OrganisationClaimService $service) {}

    public function form(string $kind, string $id): Response
    {
        $inst = $this->service->institution($kind, $id);
        abort_if($inst === null, 404);
        $official = $this->service->officialContact($inst);

        return $this->private(response()->view('public.partners.claim', [
            'kind' => $kind, 'id' => $id, 'inst' => $inst, 'lock' => $this->service->lockState($inst),
            'masked' => $official ? PublicIntake::mask($official) : null,
        ]));
    }

    public function submit(string $kind, string $id, Request $request): RedirectResponse
    {
        $inst = $this->service->institution($kind, $id);
        abort_if($inst === null, 404);
        if ($request->filled('website')) { // honeypot
            return redirect()->route('public.org-claim', ['kind' => $kind, 'id' => $id])->with('org_claim_sent', true);
        }
        $data = $request->validate([
            'claimant_name' => 'required|string|min:2|max:160', 'claimant_position' => 'required|string|min:2|max:120',
            'claimant_email' => 'required|email:rfc|max:190', 'claimant_phone' => 'nullable|string|max:32',
            'statement' => 'nullable|string|max:3000', 'dispute' => 'nullable|boolean', 'consent' => 'accepted',
            'doc_authority' => ['required', ...self::FILE], 'doc_id' => ['required', ...self::FILE],
        ]);
        $data['locale'] = app()->getLocale();
        $out = $this->service->submit($inst, $data, ['authority' => $request->file('doc_authority'), 'id' => $request->file('doc_id')], $request->ip(), $request->boolean('dispute'));

        return redirect()->route('public.org-claim.status', ['token' => $out['token']]);
    }

    public function status(string $token): Response
    {
        return $this->private(response()->view('public.partners.claim-status', ['c' => $this->find($token), 'token' => $token]));
    }

    public function verify(string $token, Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => 'required|string|max:12']);
        $ok = $this->service->verify($this->find($token), $data['code']);

        return back()->with($ok ? 'status_ok' : 'status_error', __($ok ? 'org_claim.status.verified' : 'partner_apply.errors.wrong_code'));
    }

    public function resend(string $token): RedirectResponse
    {
        $this->service->resend($this->find($token));

        return back()->with('status_ok', __('partner_apply.status.code_resent'));
    }

    public function respond(string $token, Request $request): RedirectResponse
    {
        $data = $request->validate(['response' => 'required|string|min:5|max:5000', 'doc_extra' => ['nullable', ...self::FILE]]);
        $this->service->respond($this->find($token), $data['response'], ['extra' => $request->file('doc_extra')]);

        return back()->with('status_ok', __('partner_apply.status.responded'));
    }

    private function find(string $token): OrganisationClaim
    {
        $c = $this->service->findByToken($token);
        abort_if($c === null, 404);

        return $c;
    }

    private function private(Response $response): Response
    {
        return $response->header('Referrer-Policy', 'no-referrer')->header('X-Robots-Tag', 'noindex, nofollow')->header('Cache-Control', 'no-store, private');
    }
}
