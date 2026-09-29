<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Application\Identity\Rbac\PlatformAuthority;
use App\Domain\Tenancy\TenantContext;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Shared plumbing for the long-tail desk actions (MiscConfigActions, MiscOperationsActions, MiscPlatformActions):
 * one builder (label/permission/refusal handling come from WorkflowAction), tenant-scoped record pickers, JSON
 * fields and the API validation rules. Texts: resources/lang/{en,fr}/misc_actions.php.
 */
final class MiscSupport
{
    public const L = 'misc_actions';

    /** @var array<string, list<string>> */
    private static array $columns = [];

    /**
     * @param  array<int, mixed>|\Closure  $schema
     * @param  \Closure(array): mixed  $call  receives the form data; returns the service result
     */
    public static function op(string $name, ?string $permission, string $icon, array|\Closure $schema, \Closure $call, bool $showResult = false): Action
    {
        $action = WorkflowAction::make($name, $permission, self::L)->icon($icon);
        if ($schema === []) {
            $action->requiresConfirmation();
        } else {
            $action->schema($schema);
        }

        return $action->action(function (Action $action, array $data) use ($permission, $call, $name, $showResult) {
            $result = WorkflowAction::run($action, $permission, fn () => $call($data), __(self::L.".{$name}.done"));
            if ($showResult && $result !== null) {
                Notification::make()->info()->title(__(self::L.".{$name}.label"))
                    ->body(mb_strimwidth(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), 0, 1500, '…'))->persistent()->send();
            }

            return $result;
        });
    }

    public static function f(string $field): string
    {
        return __(self::L.'.fields.'.$field);
    }

    public static function tenant(): string
    {
        return app(TenantContext::class)->id();
    }

    public static function user(): \App\Models\User
    {
        return auth()->user();
    }

    public static function isPlatformTenant(): bool
    {
        $t = rescue(fn () => app(TenantContext::class)->id(), null, false);

        return $t !== null && app(PlatformAuthority::class)->isPlatformTenant($t);
    }

    /** Runs the API's validation rules on the form data (same messages, shown by WorkflowAction::run). */
    public static function validate(array $data, array $rules): array
    {
        return Validator::make($data, $rules)->validate();
    }

    /** Drops empty optional values so `sometimes` / `nullable` rules behave as on the API. */
    public static function filled(array $data): array
    {
        return array_filter($data, fn ($v) => $v !== null && $v !== '' && $v !== []);
    }

    /** Parses a JSON text field; blank => null. */
    public static function json(?string $text, string $field, bool $arrayOnly = true): mixed
    {
        if ($text === null || trim($text) === '') {
            return null;
        }
        $v = json_decode($text, true);
        if (json_last_error() !== JSON_ERROR_NONE || ($arrayOnly && ! is_array($v))) {
            throw ValidationException::withMessages([$field => __(self::L.'.invalid_json', ['field' => self::f($field)])]);
        }

        return $v;
    }

    /**
     * Record picker options: tenant-scoped when the table has tenant_id, newest first, labelled by the first
     * existing columns of $label.
     *
     * @param  list<string>  $label
     * @return array<string, string>
     */
    public static function rows(string $table, array $label, ?\Closure $scope = null, string $key = 'id', bool $tenantScoped = true): array
    {
        $have = self::$columns[$table] ??= Schema::getColumnListing($table);
        $q = DB::table($table);
        if ($tenantScoped && in_array('tenant_id', $have, true)) {
            $q->where('tenant_id', self::tenant());
        }
        if ($scope) {
            $scope($q);
        }
        if (in_array('created_at', $have, true)) {
            $q->orderByDesc('created_at');
        }
        $cols = array_values(array_intersect($label, $have));

        return $q->limit(200)->get()->mapWithKeys(function ($r) use ($cols, $key) {
            $parts = array_filter(array_map(fn ($c) => is_scalar($r->{$c} ?? null) ? (string) $r->{$c} : null, $cols), fn ($v) => $v !== null && $v !== '');

            return [(string) $r->{$key} => $parts !== [] ? implode(' · ', $parts) : (string) $r->{$key}];
        })->all();
    }

    /** @return array<string, string> active members of the current tenant */
    public static function members(): array
    {
        return DB::table('tenant_memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.tenant_id', self::tenant())->where('m.status', 'ACTIVE')->orderBy('u.full_name')->limit(300)
            ->pluck('u.full_name', 'u.id')->map(fn ($n) => (string) $n)->all();
    }

    /** @return array<string, string> customers of the current tenant (party id => name) */
    public static function customers(): array
    {
        return DB::table('tenant_customers as c')->join('parties as p', 'p.id', '=', 'c.party_id')
            ->where('c.tenant_id', self::tenant())->orderBy('p.display_name')->limit(300)->pluck('p.display_name', 'p.id')->map(fn ($n) => (string) $n)->all();
    }

    /** @param  list<string>  $values */
    public static function options(array $values): array
    {
        return array_combine($values, $values);
    }
}
