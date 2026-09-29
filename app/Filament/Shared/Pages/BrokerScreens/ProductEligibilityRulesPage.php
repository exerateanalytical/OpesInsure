<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\BrokerScreens;

use App\Filament\Shared\Actions\InsuranceCheckActions;
use App\Models\{CarrierBrokerAgreementRecord, InsuranceProduct};
use BackedEnum;
use Filament\Tables\Columns\{IconColumn, TextColumn};
use Illuminate\Database\Eloquent\Builder;

/**
 * BRK-028 Product Eligibility Rules (WF-017): the ACTIVE products of the insurers the brokerage holds an ACTIVE
 * carrier-broker agreement with (CarrierBrokerAgreementRecord::visibleInPortal, the same rule as the agreements list
 * and as SellabilityService when quoting), with their eligibility rules, channels and new-business / renewal flags.
 * Rules are carrier configuration (read-only here); the eligibility check runs the rule engine exactly like
 * POST rules/eligibility (rules.evaluate, InsuranceCheckActions::checkEligibility).
 */
final class ProductEligibilityRulesPage extends BrokerScreen
{
    protected static string $key = 'product_eligibility';

    protected static ?string $slug = 'product-eligibility-rules';

    protected static string|BackedEnum|null $navigationIcon = 'lucide-list-checks';

    protected static ?int $navigationSort = 41;

    protected function query(): Builder
    {
        $carriers = CarrierBrokerAgreementRecord::query()->visibleInPortal()->where('status', 'ACTIVE')->select('carrier_id');

        return InsuranceProduct::query()->with('carrier')->where('status', 'ACTIVE')->whereIn('carrier_id', $carriers);
    }

    protected function defaultSort(): string
    {
        return 'code';
    }

    protected function columns(): array
    {
        return [
            TextColumn::make('carrier_name')->label(self::col('carrier'))
                ->state(fn (InsuranceProduct $record) => $record->carrier?->trade_name ?: $record->carrier?->legal_name ?: '—'),
            self::text('code', 'product_code')->searchable()->sortable(),
            self::text('name', 'product')->searchable(),
            self::text('line_code', 'line')->sortable(),
            TextColumn::make('eligibility_summary')->label(self::col('eligibility_rules'))->wrap()
                ->state(fn (InsuranceProduct $record) => self::summarise($record->eligibility_rules)),
            TextColumn::make('channels')->label(self::col('channels'))
                ->state(fn (InsuranceProduct $record) => implode(', ', array_map('strval', (array) ($record->distribution_channels ?? []))) ?: '—'),
            IconColumn::make('new_business_allowed')->label(self::col('new_business'))->boolean(),
            IconColumn::make('renewal_allowed')->label(self::col('renewal'))->boolean(),
            self::date('effective_from', 'effective_from', false),
        ];
    }

    protected function headerActions(): array
    {
        return [InsuranceCheckActions::checkEligibility()];
    }

    /** Short human summary of the product's eligibility rules (JSON array or object). */
    public static function summarise(mixed $rules): string
    {
        if (is_string($rules)) {
            $rules = json_decode($rules, true);
        }
        $rules = (array) ($rules ?? []);
        if ($rules === []) {
            return __('broker_screens_b.product_eligibility.no_rules');
        }
        $parts = [];
        foreach ($rules as $k => $v) {
            if (is_array($v)) {
                $field = $v['field'] ?? $v['fact'] ?? (is_string($k) ? $k : null);
                $op = $v['operator'] ?? $v['op'] ?? '';
                $value = $v['value'] ?? $v['values'] ?? null;
                $parts[] = trim(($field ?? '#'.$k).' '.$op.' '.(is_array($value) ? implode('/', array_map('strval', $value)) : (string) $value));
            } else {
                $parts[] = $k.': '.(is_bool($v) ? ($v ? 'yes' : 'no') : (string) $v);
            }
        }

        return \Illuminate\Support\Str::limit(implode(' · ', $parts), 180);
    }
}
