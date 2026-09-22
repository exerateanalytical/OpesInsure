<?php

use App\Domain\Shared\Money;

test('money adds exact minor units', function () {
    expect((new Money(1500, 'XAF'))->add(new Money(500, 'XAF'))->minor)->toBe(2000);
});

test('money rejects currency mismatch', function () {
    (new Money(1500, 'XAF'))->add(new Money(1, 'EUR'));
})->throws(DomainException::class);
