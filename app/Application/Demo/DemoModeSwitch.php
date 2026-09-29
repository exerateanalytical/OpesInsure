<?php

declare(strict_types=1);

namespace App\Application\Demo;

use App\Application\Identity\MobileAuthService;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoInstitutionalSeeder;
use Database\Seeders\DemoMobileAccountSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * S13 — leaving (demo:exit) and re-entering (demo:enter) demo mode, one call each. Demo rows are never deleted:
 * they stay flagged is_demo and are hidden from every read while demo mode is off (DemoMode + query grammar).
 */
final class DemoModeSwitch
{
    /** The bootstrap admin seeded by DatabaseSeeder; may be the owner's real login, so never suspended by default. */
    public const BOOTSTRAP_ADMIN_EMAIL = 'admin@opesinsure.local';

    public function __construct(private DemoMode $mode, private MobileAuthService $auth)
    {
    }

    /**
     * What demo mode currently touches. Read with demo rows revealed.
     *
     * @return array{mode: array<string, mixed>, records: array<string, int>, users: list<array{id: string, email: ?string, phone: ?string, status: string}>, bootstrap_admin: ?array{id: string, status: string}, demo_tenant: ?array{id: string, status: string}}
     */
    public function plan(bool $includeBootstrapAdmin = false): array
    {
        return DemoMode::reveal(function () use ($includeBootstrapAdmin) {
            $records = [];
            foreach (DemoMode::TABLES as $table) {
                if (Schema::hasColumn($table, 'is_demo')) {
                    $records[$table] = DB::table($table)->where('is_demo', true)->count();
                }
            }
            $admin = User::where('email', self::BOOTSTRAP_ADMIN_EMAIL)->first();
            $tenant = DB::table('tenants')->where('slug', DemoInstitutionalSeeder::TENANT_SLUG)->first(['id', 'status']);

            return [
                'mode' => $this->mode->status(),
                'records' => $records,
                'users' => $this->demoUsers($includeBootstrapAdmin)->map(fn (User $u) => ['id' => $u->id, 'email' => $u->email, 'phone' => $u->phone_e164, 'status' => (string) $u->status])->values()->all(),
                'bootstrap_admin' => $admin ? ['id' => $admin->id, 'status' => (string) $admin->status] : null,
                'demo_tenant' => $tenant ? ['id' => (string) $tenant->id, 'status' => (string) $tenant->status] : null,
            ];
        });
    }

    /** Turns demo mode off, suspends demo users (never deletes them) and revokes their sessions. */
    public function exit(string $actor, bool $includeBootstrapAdmin = false): array
    {
        $derived = [];
        DemoMode::reveal(function () use ($actor, $includeBootstrapAdmin, &$derived) {
            DB::transaction(function () use ($actor, $includeBootstrapAdmin, &$derived) {
                $this->mode->set(false, $actor);
                $derived = $this->flagDerived($includeBootstrapAdmin);
                foreach ($this->demoUsers($includeBootstrapAdmin) as $user) {
                    $user->forceFill(['status' => 'SUSPENDED'])->save();
                    $this->auth->revokeAllSessions($user);
                }
                DB::table('tenants')->where('slug', DemoInstitutionalSeeder::TENANT_SLUG)->update(['status' => 'SUSPENDED', 'updated_at' => now()]);
            });
        });

        return [...$this->plan($includeBootstrapAdmin), 'derived' => $derived];
    }

    /** Turns demo mode back on and reactivates the demo users (for a staging-like demo host). */
    public function enter(string $actor): array
    {
        DemoMode::reveal(function () use ($actor) {
            DB::transaction(function () use ($actor) {
                $this->mode->set(true, $actor);
                foreach ($this->demoUsers(false) as $user) {
                    if ($user->status === 'SUSPENDED') {
                        $user->forceFill(['status' => 'ACTIVE'])->save();
                    }
                }
                DB::table('tenants')->where('slug', DemoInstitutionalSeeder::TENANT_SLUG)->where('status', 'SUSPENDED')->update(['status' => 'ACTIVE', 'updated_at' => now()]);
            });
        });

        return $this->plan();
    }

    /** Link column => parent table: a row pointing at a demo parent is itself demo. */
    private const LINKS = [
        'party_id' => 'parties', 'claimant_party_id' => 'parties', 'policy_id' => 'policies', 'claim_id' => 'claims',
        'proposal_id' => 'proposals', 'quote_id' => 'quotes', 'payment_intent_id' => 'payment_intents', 'partner_id' => 'partners',
        'tenant_id' => 'tenants',
    ];

    /**
     * Flags (is_demo = true, data_origin = DEMO_SYNTHETIC when empty) every row that belongs to demo data but was
     * never flagged: the demo users' own parties, and everything the demo personas created through the app (their
     * purchases, payments, claims, commissions...), following the link columns until nothing changes.
     *
     * @return array<string, int> rows newly flagged per table
     */
    public function flagDerived(bool $includeBootstrapAdmin = false): array
    {
        $flagged = [];
        $partyIds = $this->demoUsers($includeBootstrapAdmin)->pluck('party_id')->filter()->values()->all();
        if ($partyIds !== []) {
            $n = DB::table('parties')->whereIn('id', $partyIds)->where('is_demo', false)->update(['is_demo' => true]);
            if ($n > 0) {
                $flagged['parties'] = $n;
            }
        }

        $columns = collect(DB::select("select table_name, column_name from information_schema.columns where table_schema = current_schema() and table_name in ('".implode("','", DemoMode::TABLES)."')"))
            ->groupBy('table_name')->map(fn ($c) => $c->pluck('column_name')->all());

        for ($pass = 0; $pass < 6; $pass++) {
            $changed = 0;
            foreach (DemoMode::TABLES as $table) {
                $cols = $columns[$table] ?? [];
                if (! in_array('is_demo', $cols, true)) {
                    continue;
                }
                $conditions = [];
                foreach (self::LINKS as $column => $parent) {
                    if (in_array($column, $cols, true) && $parent !== $table) {
                        $conditions[] = "\"{$column}\" in (select id from \"{$parent}\" where is_demo)";
                    }
                }
                if ($conditions === []) {
                    continue;
                }
                $origin = in_array('data_origin', $cols, true) ? ", data_origin = coalesce(data_origin, 'DEMO_SYNTHETIC')" : '';
                $n = DB::update("update \"{$table}\" set is_demo = true{$origin} where is_demo is not true and (".implode(' or ', $conditions).')');
                if ($n > 0) {
                    $flagged[$table] = ($flagged[$table] ?? 0) + $n;
                    $changed += $n;
                }
            }
            if ($changed === 0) {
                break;
            }
        }

        return $flagged;
    }

    /** Visible (non-revealed) demo rows per table: all zero when demo mode is off and the filter works. */
    public function leaks(): array
    {
        $out = [];
        foreach (DemoMode::TABLES as $table) {
            if (Schema::hasColumn($table, 'is_demo')) {
                $out[$table] = DB::table($table)->where('is_demo', true)->count();
            }
        }

        return array_filter($out);
    }

    /** @return \Illuminate\Support\Collection<int, User> */
    private function demoUsers(bool $includeBootstrapAdmin): \Illuminate\Support\Collection
    {
        $emails = array_merge(array_column(DatabaseSeeder::DEMO_ACCOUNTS, 'email'), array_column(DemoMobileAccountSeeder::ACCOUNTS, 'email'));
        if (! $includeBootstrapAdmin) {
            $emails = array_values(array_diff($emails, [self::BOOTSTRAP_ADMIN_EMAIL]));
        }

        return DemoMode::reveal(fn () => User::query()
            ->where(fn ($q) => $q->whereIn('email', $emails)
                ->orWhere('email', 'like', '%@'.DemoEnvironment::IDENTITY_DOMAIN)
                ->orWhereIn('party_id', DB::table('parties')->where('is_demo', true)->select('id')))
            ->when(! $includeBootstrapAdmin, fn ($q) => $q->where(fn ($w) => $w->whereNull('email')->orWhere('email', '!=', self::BOOTSTRAP_ADMIN_EMAIL)))
            ->orderBy('email')->get());
    }
}
