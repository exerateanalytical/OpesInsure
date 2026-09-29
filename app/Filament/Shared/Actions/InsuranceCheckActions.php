<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Application\Identity\OwnershipScope;
use App\Application\Rating\RatingService;
use App\Application\Rules\RuleEngine;
use App\Application\Rules\RuleSetService;
use App\Application\Temporal\ReferenceInstant;
use App\Models\InsuranceProduct;
use App\Models\Quote;
use Carbon\CarbonImmutable;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;

/**
 * Stateless insurance checks (UI coverage batch 27): eligibility and completeness through the rule engine, tariff preview
 * of a quote. Same engine, same permission, same validation as the API routes (RuleEvaluationController, RatingController@preview).
 *   checkEligibility   POST insurance/eligibility/check    rules.evaluate  RuleEngine::eligibility
 *   checkCompleteness  POST insurance/completeness/check   rules.evaluate  RuleEngine::completeness
 *   checkRate          POST insurance/rate                 quotes.rate     RatingService::tariffFor + price
 */
final class InsuranceCheckActions
{
    private const L = RiskTransferSupport::L;

    private static function products(): array
    {
        return InsuranceProduct::query()->orderBy('code')->limit(500)->get()->mapWithKeys(fn (InsuranceProduct $p) => [$p->id => $p->code.' v'.$p->version])->all();
    }

    private static function facts(array $data): array
    {
        return collect((array) ($data['facts'] ?? []))->map(fn ($v) => is_numeric($v) ? $v + 0 : (in_array($v, ['true', 'false'], true) ? $v === 'true' : $v))->all();
    }

    private static function at(array $data): ?\DateTimeImmutable
    {
        return ! empty($data['reference_date']) ? new \DateTimeImmutable((string) $data['reference_date']) : null;
    }

    public static function checkEligibility(): Action
    {
        $p = 'rules.evaluate';

        return WorkflowAction::make('checkEligibility', $p, self::L)->icon('lucide-list-checks')->color('gray')
            ->schema([
                Select::make('insurance_product_id')->label(RiskTransferSupport::f('product'))->options(fn () => self::products())->searchable()->requiredWithout('line_code'),
                TextInput::make('line_code')->label(RiskTransferSupport::f('line_code'))->maxLength(32),
                KeyValue::make('facts')->label(RiskTransferSupport::f('facts')),
                DatePicker::make('reference_date')->label(RiskTransferSupport::f('reference_date')),
            ])
            ->action(function (Action $action, array $data) use ($p) {
                $out = WorkflowAction::run($action, $p, function () use ($data) {
                    $products = ! empty($data['insurance_product_id'])
                        ? collect([InsuranceProduct::findOrFail($data['insurance_product_id'])])
                        : InsuranceProduct::where('line_code', strtoupper((string) $data['line_code']))->where('status', 'ACTIVE')->orderBy('code')->get();
                    $engine = app(RuleEngine::class);

                    return $products->mapWithKeys(fn (InsuranceProduct $prod) => [$prod->code => $engine->eligibility($prod, self::facts($data), self::at($data), ['type' => 'eligibility_check', 'id' => null])['outcome']->value])->all();
                }, __(self::L.'.checkEligibility.done'));
                RiskTransferSupport::show(__(self::L.'.checkEligibility.label'), $out);
            });
    }

    public static function checkCompleteness(): Action
    {
        $p = 'rules.evaluate';

        return WorkflowAction::make('checkCompleteness', $p, self::L)->icon('lucide-clipboard-list')->color('gray')
            ->schema([
                Select::make('operation')->label(RiskTransferSupport::f('operation'))->options(RiskTransferSupport::codes(RuleSetService::OPERATIONS))->required(),
                Select::make('insurance_product_id')->label(RiskTransferSupport::f('product'))->options(fn () => self::products())->searchable(),
                TextInput::make('line_code')->label(RiskTransferSupport::f('line_code'))->maxLength(32)->requiredWithout('insurance_product_id'),
                KeyValue::make('facts')->label(RiskTransferSupport::f('facts')),
                DatePicker::make('reference_date')->label(RiskTransferSupport::f('reference_date')),
            ])
            ->action(function (Action $action, array $data) use ($p) {
                $out = WorkflowAction::run($action, $p, function () use ($data) {
                    $product = ! empty($data['insurance_product_id']) ? InsuranceProduct::findOrFail($data['insurance_product_id']) : null;
                    $c = app(RuleEngine::class)->completeness($data['operation'], (string) ($product?->line_code ?? $data['line_code']), $product, self::facts($data), self::at($data),
                        ['type' => 'completeness_check', 'id' => null]);

                    $r = $c['result']->toArray();
                    $scalar = fn ($v) => is_scalar($v) || $v === null ? $v : json_encode($v);

                    return ['outcome' => $scalar($r['outcome'] ?? null), 'blocking' => $scalar($r['blocking'] ?? null), 'evaluation_id' => $c['evaluation_id']];
                }, __(self::L.'.checkCompleteness.done'));
                RiskTransferSupport::show(__(self::L.'.checkCompleteness.label'), $out);
            });
    }

    public static function checkRate(): Action
    {
        $p = 'quotes.rate';

        return WorkflowAction::make('checkRate', $p, self::L)->icon('lucide-calculator')->color('gray')
            ->schema([
                Select::make('quote_id')->label(RiskTransferSupport::f('quote'))->searchable()->required()
                    ->getSearchResultsUsing(fn (string $search) => app(OwnershipScope::class)->apply(Quote::where('tenant_id', RiskTransferSupport::tenant()), auth()->user())
                        ->where('quote_number', 'ilike', "%{$search}%")->limit(50)->pluck('quote_number', 'id')->all())
                    ->getOptionLabelUsing(fn ($value) => Quote::where('tenant_id', RiskTransferSupport::tenant())->whereKey($value)->value('quote_number')),
                Select::make('product_id')->label(RiskTransferSupport::f('product'))->options(fn () => self::products())->searchable(),
                DatePicker::make('reference_date')->label(RiskTransferSupport::f('reference_date')),
            ])
            ->action(function (Action $action, array $data) use ($p) {
                $out = WorkflowAction::run($action, $p, function () use ($data) {
                    $quote = app(OwnershipScope::class)->apply(Quote::where('tenant_id', RiskTransferSupport::tenant()), auth()->user())->findOrFail($data['quote_id']);
                    $tz = (string) config('app.timezone', 'Africa/Douala');
                    $at = ReferenceInstant::at(! empty($data['reference_date']) ? CarbonImmutable::parse((string) $data['reference_date'], $tz)->setTime(12, 0) : now(), $tz);
                    $s = app(RatingService::class);
                    $rows = [];
                    foreach (InsuranceProduct::where('line_code', $quote->line_code)->when($data['product_id'] ?? null, fn ($q, $id) => $q->whereKey($id))->orderBy('id')->get() as $product) {
                        $tariff = $s->tariffFor($product, $at, null);
                        if (! $tariff) {
                            continue;
                        }
                        try {
                            $res = $s->price($tariff, $quote->risk_facts, $quote->line_code, $quote->tenant_id, $at, null);
                            $pricing = $res['pricing']->toArray();
                            $rows[$product->code] = $pricing['total_minor'] ?? $pricing['gross_premium_minor'] ?? json_encode($pricing);
                        } catch (DomainException $e) {
                            $rows[$product->code] = $e->getMessage();
                        }
                    }

                    return $rows;
                }, __(self::L.'.checkRate.done'));
                RiskTransferSupport::show(__(self::L.'.checkRate.label'), $out === [] ? [__(self::L.'.checkRate.none') => null] : $out);
            });
    }
}
