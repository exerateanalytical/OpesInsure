<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Shared helpers of the regulatory / distribution / CRM / rule-set / collections desktop actions
 * (RegulatoryReturnActions, CarrierOnboardingActions, CrmLeadActions, RuleSetActions, CollectionActions).
 * Labels: resources/lang/{en,fr}/regulatory_crm_actions.php.
 */
final class RegulatoryCrmSupport
{
    public const L = 'regulatory_crm_actions';

    /** Same rules as the API controller; a ValidationException is shown by WorkflowAction::run. */
    public static function check(array $data, array $rules): array
    {
        return Validator::make(self::present($data), $rules)->validate();
    }

    /** Blank form fields are "not sent", as in the API. */
    public static function present(array $data): array
    {
        return array_filter($data, fn ($v) => $v !== null && $v !== '' && $v !== []);
    }

    /** Decodes a JSON form field (object/array) the API receives as a JSON body member. */
    public static function json(array $data, string $key, bool $required = true): ?array
    {
        $raw = $data[$key] ?? null;
        if (is_array($raw)) {
            return $raw;
        }
        if (blank($raw)) {
            if ($required) {
                throw ValidationException::withMessages([$key => __(self::L.'.json_required', ['field' => self::f($key)])]);
            }

            return null;
        }
        $v = json_decode((string) $raw, true);
        if (! is_array($v)) {
            throw ValidationException::withMessages([$key => __(self::L.'.json_invalid', ['field' => self::f($key)])]);
        }

        return $v;
    }

    public static function jsonField(string $name, bool $required = true): Textarea
    {
        return Textarea::make($name)->label(self::f($name))->rows(6)->required($required)->helperText(__(self::L.'.json_help'));
    }

    public static function member(string $name): Select
    {
        return Select::make($name)->label(self::f($name))->searchable()
            ->getSearchResultsUsing(fn (string $search) => User::whereHas('memberships', fn ($m) => $m->where('tenant_id', self::tenant())->where('status', 'ACTIVE'))
                ->where('full_name', 'ilike', "%{$search}%")->limit(20)->pluck('full_name', 'id')->all())
            ->getOptionLabelUsing(fn ($value) => User::find($value)?->full_name);
    }

    public static function idempotency(): Hidden
    {
        return Hidden::make('idempotency_key')->default(fn () => (string) Str::uuid());
    }

    /** @param  list<string>  $values */
    public static function opts(array $values, ?string $group = null): array
    {
        return collect($values)->mapWithKeys(fn ($v) => [$v => $group ? (WorkflowAction::optional(self::L.".options.{$group}.{$v}") ?? $v) : $v])->all();
    }

    public static function f(string $name): string
    {
        return __(self::L.'.fields.'.$name);
    }

    public static function done(string $name): string
    {
        return __(self::L.'.'.$name.'.done');
    }

    public static function tenant(): string
    {
        return app(TenantContext::class)->id();
    }

    public static function user(): User
    {
        return auth()->user();
    }

    public static function field(mixed $record, string $key): mixed
    {
        return is_array($record) ? ($record[$key] ?? null) : ($record->{$key} ?? null);
    }
}
