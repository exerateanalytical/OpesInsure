<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Web;

use App\Application\Audit\AuditWriter;
use App\Application\Identity\PartyResolver;
use App\Application\Privacy\DataSubjectRequestService;
use App\Application\Settings\PlatformSettings;
use App\Models\DataSubjectRequest;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

/**
 * Public marketing website (EN/FR): landing page, provider directory, the
 * informational pages the footer and the mobile app link to, and two public
 * forms that feed existing back-office queues:
 *   - /contact        -> support_tickets (same shape as Api\V1\Support\SupportController::store)
 *   - /account/delete -> DataSubjectRequestService::receive (ERASURE), the same
 *                        path the mobile app uses; compliance verifies identity
 *                        before anything is erased.
 */
final class PublicSiteController
{
    /** Static informational pages: slug => view. */
    public const PAGES = [
        'about' => 'public.pages.about',
        'how-it-works' => 'public.pages.how-it-works',
        'claims' => 'public.pages.claims',
        'faq' => 'public.pages.faq',
        'privacy' => 'public.pages.privacy',
        'terms' => 'public.pages.terms',
        'partners' => 'public.pages.partners',
    ];

    public function __construct(private PublicProviderDirectory $directory) {}

    public function home(): View
    {
        return view('public.landing', [
            'stats' => $this->directory->stats(),
            'featured' => $this->directory->featured(8),
        ]);
    }

    public function about(): View { return $this->info('about'); }

    public function howItWorks(): View { return $this->info('how-it-works'); }

    public function claims(): View { return $this->info('claims'); }

    public function faq(): View { return $this->info('faq'); }

    public function privacy(): View { return $this->info('privacy'); }

    public function terms(): View { return $this->info('terms'); }

    public function partners(): View { return $this->info('partners'); }

    private function info(string $slug): View
    {
        return view(self::PAGES[$slug], ['contacts' => $this->contacts(), 'stats' => $this->directory->stats()]);
    }

    public function providers(Request $request): View
    {
        $filters = [
            'q' => mb_substr(trim((string) $request->query('q', '')), 0, 80),
            'type' => in_array($request->query('type'), ['insurer', 'broker'], true) ? $request->query('type') : '',
            'branch' => in_array($request->query('branch'), ['IARD', 'LIFE'], true) ? $request->query('branch') : '',
            'city' => mb_substr(trim((string) $request->query('city', '')), 0, 80),
        ];

        return view('public.pages.providers', [
            'filters' => $filters,
            // Everything is rendered; non-matches are hidden server-side so the
            // list filters without JS and site.js can re-filter instantly.
            'entries' => $this->directory->all(),
            'visible' => collect($this->directory->search($filters))->map(fn ($p) => $p['kind'].'|'.$p['name'])->flip()->all(),
            'stats' => $this->directory->stats(),
            'cities' => $this->directory->cities(),
        ]);
    }

    public function contact(Request $request): View
    {
        return view('public.pages.contact', [
            'contacts' => $this->contacts(),
            'topic' => in_array($request->query('topic'), ['support', 'partner', 'claim', 'privacy'], true) ? $request->query('topic') : 'support',
        ]);
    }

    public function submitContact(Request $request, AuditWriter $audit): RedirectResponse
    {
        if ($request->filled('website')) { // honeypot
            return redirect()->route('public.contact')->with('contact_sent', 'WEB');
        }

        $data = $request->validate([
            'name' => 'required|string|min:2|max:120',
            'email' => 'required|email:rfc|max:190',
            'phone' => 'nullable|string|max:32',
            'topic' => 'required|in:support,partner,claim,privacy',
            'message' => 'required|string|min:20|max:5000',
        ]);

        $id = (string) Str::uuid();
        $number = 'WEB-'.now()->format('Ym').'-'.strtoupper(Str::random(8));
        $category = ['support' => 'PUBLIC_WEB_CONTACT', 'partner' => 'PARTNERSHIP_ENQUIRY', 'claim' => 'CLAIM_ENQUIRY', 'privacy' => 'PRIVACY_ENQUIRY'][$data['topic']];
        $description = "Name: {$data['name']}\nEmail: {$data['email']}\nPhone: ".($data['phone'] ?? '-')."\nLanguage: ".app()->getLocale()."\n\n{$data['message']}";

        DB::transaction(function () use ($id, $number, $category, $description, $data, $audit): void {
            DB::table('support_tickets')->insert([
                'id' => $id, 'tenant_id' => null, 'party_id' => null, 'ticket_number' => $number, 'type' => 'SUPPORT', 'category' => $category,
                'priority' => 'NORMAL', 'status' => 'OPEN', 'subject' => Str::limit('Website: '.$data['name'].' ('.$data['topic'].')', 195, ''),
                'description' => $description, 'sla_due_at' => now()->addHours(48), 'idempotency_key' => 'web-contact:'.$id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('support_ticket_events')->insert([
                'id' => (string) Str::uuid(), 'support_ticket_id' => $id, 'type' => 'CREATED', 'to_status' => 'OPEN', 'actor_id' => null,
                'message' => $data['message'], 'metadata' => json_encode(['sender' => 'PUBLIC_WEB', 'topic' => $data['topic']]), 'occurred_at' => now(),
            ]);
            $audit->record('support.ticket.created', 'support_ticket', $id, ['type' => 'SUPPORT', 'channel' => 'PUBLIC_WEB', 'category' => $category]);
        });

        return redirect()->route('public.contact')->with('contact_sent', $number);
    }

    public function accountDelete(): View
    {
        return view('public.pages.account-delete', ['contacts' => $this->contacts()]);
    }

    /**
     * Files an ERASURE data-subject request for the matching account. The
     * response is identical whether or not an account matches, so the form
     * cannot be used to discover who has an account. Nothing is erased here:
     * compliance verifies identity (DataSubjectRequestService::verify) first.
     */
    public function submitAccountDelete(Request $request, DataSubjectRequestService $dsr, PartyResolver $parties, AuditWriter $audit): RedirectResponse
    {
        $data = $request->validate([
            'identifier' => 'required|string|min:5|max:190',
            'full_name' => 'required|string|min:2|max:120',
            'reason' => 'nullable|string|max:1000',
            'confirm' => 'accepted',
        ]);

        if (! $request->filled('website')) { // honeypot
            $user = $this->findUser($data['identifier']);
            $party = $user ? $parties->forUser($user) : null;
            $tenant = $user ? TenantMembership::where('user_id', $user->id)->orderByRaw("CASE WHEN status = 'ACTIVE' THEN 0 ELSE 1 END")->value('tenant_id') : null;

            if ($user && $party && $tenant) {
                $open = DataSubjectRequest::where('party_id', $party->id)->where('type', 'ERASURE')->whereNotIn('status', ['COMPLETED', 'REJECTED', 'FULFILLED', 'CLOSED'])->exists();
                if (! $open) {
                    $x = $dsr->receive($tenant, ['party_id' => $party->id, 'type' => 'ERASURE', 'due_on' => now()->addDays(30)->toDateString(), 'idempotency_key' => 'web-dsr:'.$party->id.':ERASURE:'.now()->toDateString()], $user);
                    $audit->record('data_subject_request.received', 'data_subject_request', $x->id, ['type' => 'ERASURE', 'channel' => 'PUBLIC_WEB']);
                }
            } else {
                // No verifiable account: leave a trace for the privacy team so the requester still gets an answer.
                $id = (string) Str::uuid();
                DB::table('support_tickets')->insert([
                    'id' => $id, 'tenant_id' => null, 'party_id' => null, 'ticket_number' => 'WEB-'.now()->format('Ym').'-'.strtoupper(Str::random(8)),
                    'type' => 'SUPPORT', 'category' => 'ACCOUNT_DELETION_UNMATCHED', 'priority' => 'NORMAL', 'status' => 'OPEN',
                    'subject' => 'Account deletion request (no matching account)',
                    'description' => "Identifier: {$data['identifier']}\nName: {$data['full_name']}\nReason: ".($data['reason'] ?? '-'),
                    'sla_due_at' => now()->addDays(3), 'idempotency_key' => 'web-dsr-unmatched:'.$id, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $audit->record('support.ticket.created', 'support_ticket', $id, ['type' => 'SUPPORT', 'channel' => 'PUBLIC_WEB', 'category' => 'ACCOUNT_DELETION_UNMATCHED']);
            }
        }

        return redirect()->route('public.account.delete')->with('deletion_received', true);
    }

    /** Fallback for app deep links (https://…/app/…) opened without the app installed. */
    public function appLink(Request $request, ?string $path = null): View
    {
        return view('public.pages.open-app', ['path' => '/app/'.ltrim((string) $path, '/')]);
    }

    public function sitemap(): Response
    {
        $paths = ['/', '/providers', '/download', ...array_map(fn ($s) => '/'.$s, array_keys(self::PAGES)), '/contact', '/account/delete'];

        return response()->view('public.sitemap', ['paths' => $paths], 200)->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    public function robots(): Response
    {
        $body = "User-agent: *\nDisallow: /admin\nDisallow: /api/\nDisallow: /app/\nDisallow: /verify\nAllow: /\n\nSitemap: ".url('/sitemap.xml')."\n";

        return response($body, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /** @return array{email: ?string, phone: ?string, whatsapp: ?string, whatsapp_url: ?string, partner_email: ?string} */
    private function contacts(): array
    {
        try {
            return app(PlatformSettings::class)->supportContacts();
        } catch (Throwable) {
            return ['email' => config('services.support.email'), 'phone' => config('services.support.phone'), 'whatsapp' => null, 'whatsapp_url' => null, 'partner_email' => config('services.support.partner_email')];
        }
    }

    private function findUser(string $identifier): ?User
    {
        $identifier = trim($identifier);
        if (str_contains($identifier, '@')) {
            return User::whereRaw('lower(email) = ?', [mb_strtolower($identifier)])->first();
        }

        $digits = preg_replace('/\D+/', '', $identifier) ?? '';
        if (strlen($digits) === 9) {
            $digits = '237'.$digits;
        }

        return $digits === '' ? null : User::where('phone_e164', '+'.$digits)->first();
    }
}
