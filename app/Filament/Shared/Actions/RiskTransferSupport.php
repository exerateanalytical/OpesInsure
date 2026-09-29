<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Domain\Tenancy\TenantContext;
use App\Models\Claim;
use App\Models\Policy;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;

/**
 * Shared helpers of the risk-transfer workbench actions (reinsurance, co-insurance, accumulation, catastrophe events,
 * legal matters, developer portal, carrier connectors, capability profiles, compliance catalogue, insurance checks).
 * Labels live in resources/lang/{en,fr}/risk_transfer_actions.php.
 */
final class RiskTransferSupport
{
    public const L = 'risk_transfer_actions';

    public static function tenant(): string
    {
        return app(TenantContext::class)->id();
    }

    public static function user(): User
    {
        /** @var User */
        return auth()->user();
    }

    public static function f(string $key): string
    {
        return __(self::L.'.fields.'.$key);
    }

    /** @param  list<string>  $values */
    public static function codes(array $values): array
    {
        return array_combine($values, $values);
    }

    /** @return array<string, string> reinsurers (optionally only one role) of the current tenant */
    public static function reinsurers(?string $role = null, bool $excludeBrokers = false): array
    {
        return DB::table('reinsurers')->where('tenant_id', self::tenant())
            ->when($role, fn ($q) => $q->where('role', $role))->when($excludeBrokers, fn ($q) => $q->where('role', '!=', 'REINSURANCE_BROKER'))
            ->orderBy('code')->limit(500)->get()->mapWithKeys(fn ($r) => [$r->id => $r->code.' · '.$r->name])->all();
    }

    /**
     * Policies of the current tenant; inside /insurer only the caller's own carrier's (PortalScope::narrowTable, a no-op
     * outside a portal). The submitted id is re-checked server side, so a forged id of another insurer is refused.
     */
    public static function policySelect(string $name = 'policy_id'): Select
    {
        return Select::make($name)->label(self::f('policy'))->searchable()
            ->getSearchResultsUsing(fn (string $search) => self::own(Policy::where('tenant_id', self::tenant()), 'policies')->where('policy_number', 'ilike', "%{$search}%")->limit(50)->pluck('policy_number', 'id')->all())
            ->getOptionLabelUsing(fn ($value) => self::own(Policy::where('tenant_id', self::tenant()), 'policies')->whereKey($value)->value('policy_number'))
            ->rule(fn () => self::ownRule('policies'));
    }

    public static function claimSelect(string $name = 'claim_id'): Select
    {
        return Select::make($name)->label(self::f('claim'))->searchable()
            ->getSearchResultsUsing(fn (string $search) => self::own(Claim::where('tenant_id', self::tenant()), 'claims')->where('claim_number', 'ilike', "%{$search}%")->limit(50)->pluck('claim_number', 'id')->all())
            ->getOptionLabelUsing(fn ($value) => self::own(Claim::where('tenant_id', self::tenant()), 'claims')->whereKey($value)->value('claim_number'))
            ->rule(fn () => self::ownRule('claims'));
    }

    /** @template T of \Illuminate\Database\Eloquent\Builder  @param T $q  @return T */
    private static function own(\Illuminate\Database\Eloquent\Builder $q, string $table): \Illuminate\Database\Eloquent\Builder
    {
        return \App\Application\WebExperiences\PortalScope::narrowTable($q, $table);
    }

    /** Validation rule: the chosen id is a row of $table in the tenant that the caller may see (own carrier in /insurer). */
    private static function ownRule(string $table): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($table): void {
            if ($value === null || $value === '') {
                return;
            }
            $ok = DB::table($table)->where('tenant_id', self::tenant())->where('id', (string) $value)->exists()
                && \App\Application\WebExperiences\PortalScope::visibleOf($table, [(string) $value]) !== [];
            if (! $ok) {
                $fail(__('workflow_actions.denied'));
            }
        };
    }

    /**
     * Rows of a workbench list that the caller may see: inside /insurer, rows tied to a policy / claim of another
     * carrier are dropped (rows with no link stay: they are tenant-level, e.g. a treaty-wide arrangement).
     *
     * @param  array<string, array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    public static function ownRows(array $rows, string $column, string $table): array
    {
        if (\App\Application\WebExperiences\PortalScope::panel() === null) {
            return $rows;
        }
        $ids = array_values(array_unique(array_filter(array_map(fn ($r) => isset($r[$column]) ? (string) $r[$column] : null, $rows))));
        $keep = \App\Application\WebExperiences\PortalScope::visibleOf($table, $ids);

        return array_filter($rows, fn ($r) => empty($r[$column]) || in_array((string) $r[$column], $keep, true));
    }

    /** Result of a read-only computation (preview, check) shown to the user; scalar top-level values only. */
    public static function show(string $title, mixed $result): void
    {
        $rows = [];
        foreach ((array) (is_object($result) ? (array) $result : $result) as $k => $v) {
            if (is_scalar($v) || $v === null) {
                $rows[] = $k.': '.($v === null ? '—' : (is_bool($v) ? ($v ? 'true' : 'false') : (string) $v));
            } elseif (is_array($v)) {
                $rows[] = $k.': '.count($v);
            }
            if (count($rows) >= 20) {
                break;
            }
        }
        Notification::make()->info()->title($title)->body(implode("\n", $rows))->persistent()->send();
    }

    /** Blank strings from optional form fields become null / are dropped, like an API client that omits them. */
    public static function clean(array $data): array
    {
        return array_filter($data, fn ($v) => $v !== null && $v !== '' && $v !== []);
    }
}
