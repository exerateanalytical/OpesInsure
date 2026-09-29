<?php

declare(strict_types=1);

namespace App\Application\Identity\Rbac;

use Closure;
use Illuminate\Support\Facades\Event;

/**
 * R4 (launch performance): per-request memo for identity lookups that a single page repeats dozens of times — the
 * caller's memberships + roles, permission decisions, the carrier / partner the caller is linked to. A dashboard
 * used to issue 100-300 identical membership / role queries (one per tile, column, action and navigation item).
 *
 * Lifetime is ONE HTTP request: the memo lives on the current Request object, so the next request (or the next test
 * request) starts empty. Outside HTTP (queue workers, console) nothing is memoised — a long-lived worker must see a
 * revoked role at once. Any membership / role / partner write in the request flushes the memo, so a check made right
 * after a grant or a revocation sees it.
 */
final class RequestMemo
{
    private const KEY = '_r4_identity_memo';

    private const COUNTS = '_r4_count_memo';

    private static ?int $listening = null;

    /**
     * @template T
     *
     * @param  Closure(): T  $resolve
     * @return T
     */
    public static function remember(string $key, Closure $resolve): mixed
    {
        if (! self::enabled()) {
            return $resolve();
        }
        self::listen();
        $bag = app('request')->attributes;
        $memo = (array) $bag->get(self::KEY, []);
        if (array_key_exists($key, $memo)) {
            return $memo[$key];
        }
        $value = $resolve();
        $memo = (array) $bag->get(self::KEY, []); // the resolver may itself have written (or flushed)
        $memo[$key] = $value;
        $bag->set(self::KEY, $memo);

        return $value;
    }

    /** Prime several entries at once (a list preloading what its rows will ask for). No-op outside HTTP. @param array<string, mixed> $entries */
    public static function prime(array $entries): void
    {
        if (! self::enabled() || $entries === []) {
            return;
        }
        self::listen();
        $bag = app('request')->attributes;
        $bag->set(self::KEY, $entries + (array) $bag->get(self::KEY, []));
    }

    /**
     * A yes/no fact about one row of a list, asked per row (and per row action). The first ask decides it for every
     * $table row retrieved in this request with ONE call of $matching(ids) → the ids for which it holds; each answer
     * is memoised for the rest of the request. $flag must carry whatever else the answer depends on (user, tenant ...).
     *
     * @param  \Closure(list<string>): iterable<string>  $matching
     */
    public static function rowFlag(string $flag, string $table, string $id, Closure $matching): bool
    {
        return self::rowValue('flag:'.$flag, $table, $id, function (array $ids) use ($matching) {
            $hits = [];
            foreach ($matching($ids) as $hit) {
                $hits[(string) $hit] = true;
            }

            return $hits;
        }) === true;
    }

    /**
     * rowFlag() for a value: $resolve(ids) → [id => value] for the rows that have one (null for the others).
     *
     * @param  \Closure(list<string>): array<string, mixed>  $resolve
     */
    public static function rowValue(string $name, string $table, string $id, Closure $resolve): mixed
    {
        $key = fn (string $x) => 'row:'.$name.':'.$table.':'.$x;

        return self::remember($key($id), function () use ($key, $table, $id, $resolve) {
            $ids = array_values(array_unique([$id, ...self::retrievedIds($table)]));
            $values = $resolve($ids);
            $answers = [];
            foreach ($ids as $x) {
                if ($x !== $id) {
                    $answers[$key($x)] = $values[$x] ?? null;
                }
            }
            self::prime($answers);

            return $values[$id] ?? null;
        });
    }

    /**
     * count(*) of a read query, once per request for identical SQL + bindings — a dashboard shows the same governed KPI
     * in two widgets (tiles + operations). Unlike the identity memo, this one is dropped on ANY write in the request.
     */
    public static function count(\Illuminate\Database\Query\Builder $q): int
    {
        if (! self::enabled()) {
            return (int) $q->count();
        }
        self::listen();
        $bag = app('request')->attributes;
        // "now()"-relative windows (expiring in 30 days ...) are built a few ms apart by each widget: same minute = same count.
        $key = md5($q->toSql().'|'.json_encode(array_map(fn ($b) => $b instanceof \DateTimeInterface ? $b->format('Y-m-d H:i') : $b, $q->getBindings())));
        $memo = (array) $bag->get(self::COUNTS, []);
        if (! array_key_exists($key, $memo)) {
            $memo[$key] = (int) $q->count();
            $bag->set(self::COUNTS, $memo);
        }

        return $memo[$key];
    }

    public static function forget(string $key): void
    {
        if (app()->bound('request')) {
            $bag = app('request')->attributes;
            $memo = (array) $bag->get(self::KEY, []);
            unset($memo[$key]);
            $bag->set(self::KEY, $memo);
        }
    }

    public static function flush(): void
    {
        if (app()->bound('request')) {
            app('request')->attributes->remove(self::KEY);
            app('request')->attributes->remove(self::COUNTS);
        }
    }

    /** @var array<string, array<string, true>> table => ids of the models of that table retrieved in this request */
    private static array $retrieved = [];

    private static ?int $retrievedFor = null;

    /**
     * Ids of the $table models Eloquent retrieved so far in this request (tracked for PortalScope::OWNED_TABLES only),
     * so a per-row check (PortalScope::isOwnRecord on each table row / action) can decide the whole page in one query.
     *
     * @return list<string>
     */
    public static function retrievedIds(string $table): array
    {
        return self::enabled() && self::$retrievedFor === spl_object_id(app('request')) ? array_map('strval', array_keys(self::$retrieved[$table] ?? [])) : [];
    }

    private static function enabled(): bool
    {
        return app()->bound('request') && (! app()->runningInConsole() || app()->runningUnitTests());
    }

    private static function listen(): void
    {
        $events = app('events');
        if (self::$listening === spl_object_id($events)) {
            return;
        }
        self::$listening = spl_object_id($events);
        $tracked = array_flip([...\App\Application\WebExperiences\PortalScope::OWNED_TABLES, 'tenant_customers']);
        Event::listen('eloquent.retrieved: *', function (string $event, array $payload) use ($tracked) {
            $m = $payload[0] ?? null;
            if (! $m instanceof \Illuminate\Database\Eloquent\Model || ! isset($tracked[$m->getTable()]) || ! app()->bound('request')) {
                return;
            }
            $req = spl_object_id(app('request'));
            if (self::$retrievedFor !== $req) {
                self::$retrievedFor = $req;
                self::$retrieved = [];
            }
            if (count(self::$retrieved[$m->getTable()] ?? []) < 500) {
                self::$retrieved[$m->getTable()][(string) $m->getKey()] = true;
            }
        });
        // Any write (Eloquent or query builder, pivots included) to an identity table flushes the memo.
        Event::listen(\Illuminate\Database\Events\QueryExecuted::class, function ($q) {
            if (! preg_match('/^\s*(insert|update|delete|merge|truncate)\b/i', $q->sql)) {
                return;
            }
            // A write to a table a dashboard counts: the memoised counts may be stale. (Audit / session / log writes
            // made while a page renders do not invalidate them.)
            if (app()->bound('request') && preg_match('/^\s*(insert\s+into|update|delete\s+from|merge\s+into|truncate(\s+table)?)\s+"?(policies|claims|claim_payments|quotes|quote_offers|proposals|payment_intents|underwriting_cases|underwriting_referral_tasks|commission_accruals|tenant_customers|kyc_submissions|customer_attributions|policy_cancellations|policy_transactions|financial_obligations|renewal_work_items|partners|partner_leads|carrier_quote_requests)"?[\s(]/i', $q->sql)) {
                app('request')->attributes->remove(self::COUNTS);
            }
            if (preg_match('/^\s*(insert\s+into|update|delete\s+from)\s+"?(tenants|tenant_memberships|membership_roles|roles|partners|users|parties|privileged_access_grants|kpi_definitions|letterhead_assets|tenant_customers|customer_attributions|insurance_products|carriers|marketplace_publications|regulatory_regimes|product_regulatory_mappings|regulatory_branches|quote_offers|carrier_quote_requests|engine_overrides|kyc_submissions|policy_transactions|policy_cancellations|policies|proposals|proposal_disclosure_responses|underwriting_cases)"?[\s(]/i', $q->sql)) {
                self::flush();
            }
        });
    }
}
