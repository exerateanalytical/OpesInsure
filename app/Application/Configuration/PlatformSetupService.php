<?php

declare(strict_types=1);

namespace App\Application\Configuration;

use App\Application\Audit\AuditWriter;
use App\Models\ConfigurationChangeSet;
use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * REQ-SET-001 — platform setup (SCF §51–86): identity, country (CM), currency (XAF), timezone (Africa/Douala),
 * languages (FR/EN), regulatory zone (CIMA), plus a readiness checklist that the setup wizard walks.
 *
 * Identity changes are governed: they are drafted through ConfigurationGovernanceService as config_type
 * `platform.settings` / key `identity`, and this class is the applier that writes the published value into the
 * existing platform_settings singleton (no second settings store).
 */
final class PlatformSetupService
{
    public const CONFIG_TYPE = 'platform.settings';

    public const CONFIG_KEY = 'identity';

    public const LOCALES = ['fr', 'en'];

    public function __construct(private readonly ConfigurationGovernanceService $governance, private readonly AuditWriter $audit) {}

    public function identity(): array
    {
        $row = PlatformSetting::query()->orderBy('id')->first();
        $locales = $row?->supported_locales;

        return [
            'platform_name' => $row?->platform_name,
            'country_code' => $row?->country_code ?? 'CM',
            'currency_code' => $row?->currency_code ?? 'XAF',
            'default_timezone' => $row?->default_timezone ?? config('app.timezone'),
            'default_locale' => $row?->default_locale ?? 'fr',
            'supported_locales' => is_string($locales) ? json_decode($locales, true) : ($locales ?? self::LOCALES),
            'regulatory_zone' => $row?->regulatory_zone ?? 'CIMA',
            'setup_completed_at' => $row?->setup_completed_at,
        ];
    }

    /** @return list<array{code:string, label:string, done:bool, detail:?string}> */
    public function checklist(): array
    {
        $id = $this->identity();
        $mdValue = fn (string $list, ?string $code = null) => Schema::hasTable('master_data_values')
            && DB::table('master_data_values')->where('list_code', $list)->when($code, fn ($q) => $q->where('code', $code))->exists();
        $row = PlatformSetting::query()->orderBy('id')->first();

        return [
            ['code' => 'IDENTITY', 'label' => 'Platform name', 'done' => filled($id['platform_name']), 'detail' => $id['platform_name']],
            ['code' => 'COUNTRY', 'label' => 'Country of operation', 'done' => $id['country_code'] === 'CM' || $mdValue('country', $id['country_code']), 'detail' => $id['country_code']],
            ['code' => 'CURRENCY', 'label' => 'Operating currency', 'done' => $mdValue('currency', $id['currency_code']), 'detail' => $id['currency_code']],
            ['code' => 'TIMEZONE', 'label' => 'Default timezone', 'done' => in_array($id['default_timezone'], timezone_identifiers_list(), true), 'detail' => $id['default_timezone']],
            ['code' => 'LANGUAGES', 'label' => 'Languages', 'done' => in_array($id['default_locale'], (array) $id['supported_locales'], true), 'detail' => implode(',', (array) $id['supported_locales'])],
            ['code' => 'GEOGRAPHY', 'label' => 'Geography reference (cities)', 'done' => $mdValue('city'), 'detail' => null],
            ['code' => 'REGULATORY_REGISTER', 'label' => 'Official insurer register', 'done' => Schema::hasColumn('carriers', 'is_official_register') && DB::table('carriers')->where('is_official_register', true)->exists(), 'detail' => null],
            ['code' => 'APPROVAL_MATRIX', 'label' => 'Approval matrix', 'done' => Schema::hasTable('approval_matrix_rules') && DB::table('approval_matrix_rules')->exists(), 'detail' => null],
            ['code' => 'SUPPORT_CONTACTS', 'label' => 'Support contacts', 'done' => filled($row?->support_email) || filled($row?->support_phone), 'detail' => null],
        ];
    }

    public function status(): array
    {
        $checklist = $this->checklist();

        return ['identity' => $this->identity(), 'checklist' => $checklist, 'ready' => collect($checklist)->every('done')];
    }

    /** Draft a governed identity change (maker). */
    public function draftIdentity(User $maker, array $changes, string $reason, ?string $effectiveFrom = null): ConfigurationChangeSet
    {
        return $this->governance->draft($maker, self::CONFIG_TYPE, self::CONFIG_KEY, $this->validated($changes), $reason, $effectiveFrom);
    }

    /** Applier for published `platform.settings` change sets. */
    public function apply(ConfigurationChangeSet $cs): void
    {
        $values = $this->validated((array) $cs->proposed_value);
        if (! Schema::hasColumn('platform_settings', 'default_timezone')) {
            unset($values['default_timezone']);
        }
        $row = PlatformSetting::query()->orderBy('id')->first() ?? new PlatformSetting;
        $row->fill($values)->save();
    }

    public function complete(User $actor): array
    {
        $status = $this->status();
        if (! $status['ready']) {
            throw ValidationException::withMessages(['checklist' => 'Setup is incomplete: '.collect($status['checklist'])->reject('done')->pluck('code')->implode(', ')]);
        }
        $row = PlatformSetting::query()->orderBy('id')->first() ?? new PlatformSetting;
        $row->fill(['setup_completed_at' => now(), 'setup_completed_by' => $actor->id])->save();
        $this->audit->record('platform.setup_completed', 'platform_settings', (string) $row->id, ['checklist' => collect($status['checklist'])->pluck('code')->all()]);

        return $this->status();
    }

    private function validated(array $changes): array
    {
        $v = Validator::make($changes, [
            'platform_name' => 'sometimes|string|min:2|max:120',
            'country_code' => 'sometimes|string|size:2|regex:/^[A-Z]{2}$/',
            'currency_code' => 'sometimes|string|size:3|regex:/^[A-Z]{3}$/',
            'default_timezone' => 'sometimes|timezone:all',
            'default_locale' => 'sometimes|in:'.implode(',', self::LOCALES),
            'supported_locales' => 'sometimes|array|min:1',
            'supported_locales.*' => 'in:'.implode(',', self::LOCALES),
            'regulatory_zone' => 'sometimes|string|max:16',
        ]);
        $data = $v->validate();
        if ($data === []) {
            throw ValidationException::withMessages(['changes' => 'No recognised platform identity field was supplied.']);
        }
        $locales = $data['supported_locales'] ?? $this->identity()['supported_locales'];
        if (isset($data['default_locale']) && ! in_array($data['default_locale'], $locales, true)) {
            throw ValidationException::withMessages(['default_locale' => 'The default language must be one of the supported languages.']);
        }

        return $data;
    }
}
