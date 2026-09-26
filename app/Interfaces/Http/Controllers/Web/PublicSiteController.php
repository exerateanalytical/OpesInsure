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

    public function __construct(private PublicProviderDirectory $directory, private PublicMarketplace $market) {}

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

    /** Marketplace hub ("/insurance") — every line; the directories below reuse the same view. */
    public function marketplace(Request $request): View
    {
        return $this->catalogue($request, null);
    }

    /** Category directory ("/insurance/{line}"). */
    public function directory(Request $request, string $line): View
    {
        abort_unless(isset(PublicMarketplace::LINES[$line]), 404);

        return $this->catalogue($request, $line);
    }

    private function catalogue(Request $request, ?string $slug): View
    {
        $line = $slug ? PublicMarketplace::LINES[$slug] : null;
        $cat = $slug ?? (in_array($request->query('cat'), array_keys(PublicMarketplace::LINES), true) ? $request->query('cat') : null);
        $filters = [
            'line' => $cat ? PublicMarketplace::LINES[$cat] : null,
            'q' => mb_substr(trim((string) $request->query('q', '')), 0, 80),
            'providers' => array_values(array_filter(array_map('strval', (array) $request->query('provider', [])))),
            'price' => array_key_exists((string) $request->query('price'), PublicMarketplace::PRICE_BANDS) ? (string) $request->query('price') : '',
            'sort' => in_array($request->query('sort'), ['popular', 'price_asc', 'price_desc', 'name'], true) ? $request->query('sort') : 'popular',
        ];
        $rows = $this->market->filter($filters);
        $pages = max(1, (int) ceil(count($rows) / PublicMarketplace::PER_PAGE));
        $page = min($pages, max(1, (int) $request->query('page', 1)));

        return view($slug ? 'public.pages.directory' : 'public.pages.marketplace', [
            'slug' => $slug, 'cat' => $cat, 'filters' => $filters, 'total' => count($rows), 'page' => $page, 'pages' => $pages,
            'products' => array_slice($rows, ($page - 1) * PublicMarketplace::PER_PAGE, PublicMarketplace::PER_PAGE),
            'counts' => $this->market->lineCounts(), 'providerFacet' => $this->market->providers($filters['line']),
            'compare' => array_slice(array_values(array_filter((array) $request->query('cmp', []), 'is_string')), 0, 4),
        ]);
    }

    /** Side-by-side comparison of up to four published products ("/compare?p[]=CODE"). */
    public function compare(Request $request): View
    {
        $codes = array_slice(array_values(array_unique(array_filter((array) $request->query('p', []), 'is_string'))), 0, 4);
        $picked = $this->market->byCodes($codes);
        $line = $picked[0]['line'] ?? (PublicMarketplace::LINES[$request->query('line')] ?? 'MOTOR');
        $slug = array_flip(PublicMarketplace::LINES)[$line];
        $covers = collect($picked)->flatMap(fn ($p) => $p['covers'])->unique('code')->values()->all();

        return view('public.pages.compare', [
            'picked' => $picked, 'covers' => $covers, 'slug' => $slug,
            'view' => $request->query('view') === 'table' ? 'table' : 'list',
            'candidates' => $this->market->filter(['line' => $line, 'sort' => 'price_asc']),
            'counts' => $this->market->lineCounts(),
        ]);
    }

    public function login(): View { return view('public.auth.login'); }

    public function signup(): View { return view('public.auth.signup'); }

    /**
     * /account/{path}: "policies/<id>/documents" -> view public.account.pages.policies.show.documents
     * with ids = [<id>]. Id-like segments (uuids, numbers, reference codes) become "show".
     */
    public function account(?string $path = null): View
    {
        $segments = array_values(array_filter(explode('/', (string) $path), 'strlen'));
        $ids = [];
        $names = array_map(function (string $seg) use (&$ids): string {
            if (preg_match('/^[0-9a-f-]{36}$|^\d+$|^[A-Z0-9][A-Z0-9_-]*\d[A-Z0-9_-]*$/', $seg)) {
                $ids[] = $seg;

                return 'show';
            }

            return strtolower($seg);
        }, $segments);
        $view = 'public.account.pages.'.($names ? implode('.', $names) : 'dashboard');
        abort_unless(view()->exists($view), 404);

        return view($view, ['ids' => $ids, 'path' => '/account'.($segments ? '/'.implode('/', $segments) : '')]);
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
        $paths = ['/', '/insurance', ...array_map(fn ($l) => '/insurance/'.$l, array_keys(PublicMarketplace::LINES)), '/compare', '/providers', '/download', ...array_map(fn ($s) => '/'.$s, array_keys(self::PAGES)), '/contact', '/account/delete'];

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
