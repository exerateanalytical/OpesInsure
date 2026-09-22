<?php

use App\Domain\Payments\PaymentIntent;
use App\Domain\Payments\PaymentStatus;
use App\Domain\Shared\Money;

test('payment only confirms after customer authorization', function () {
    $payment = new PaymentIntent('p1', 't1', 'proposal1', new Money(10000));
    $payment->requestCustomerAuthorization('provider-123');
    $payment->confirm();
    expect($payment->status())->toBe(PaymentStatus::Succeeded);
});
