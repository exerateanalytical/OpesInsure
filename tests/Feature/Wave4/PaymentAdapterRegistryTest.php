<?php

declare(strict_types=1);

use App\Application\Payments\Adapters\ConfiguredJsonPaymentAdapter;
use App\Application\Payments\Adapters\FakePaymentAdapter;
use App\Application\Payments\Adapters\MtnMomoAdapter;
use App\Application\Payments\Adapters\OrangeMoneyAdapter;
use App\Application\Payments\Adapters\PaymentAdapterRegistry;

it('routes mtn_momo and orange_money to their dedicated adapters, not the generic JSON one', function () {
    $registry = new PaymentAdapterRegistry;

    expect($registry->for('mtn_momo'))->toBeInstanceOf(MtnMomoAdapter::class);
    expect($registry->for('orange_money'))->toBeInstanceOf(OrangeMoneyAdapter::class);
    expect($registry->for('fake'))->toBeInstanceOf(FakePaymentAdapter::class);
    expect($registry->for('maviance'))->toBeInstanceOf(ConfiguredJsonPaymentAdapter::class);
    expect($registry->for('campay'))->toBeInstanceOf(ConfiguredJsonPaymentAdapter::class);
});

it('rejects an unsupported provider', function () {
    expect(fn () => (new PaymentAdapterRegistry)->for('bitcoin'))->toThrow(InvalidArgumentException::class);
});
