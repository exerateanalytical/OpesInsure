<?php

declare(strict_types=1);

use App\Application\Logistics\Adapters\CourierAdapterRegistry;
use App\Application\Logistics\Adapters\InternalCourierAdapter;

it('routes INTERNAL to the internal courier adapter', function () {
    expect((new CourierAdapterRegistry)->for('INTERNAL'))->toBeInstanceOf(InternalCourierAdapter::class);
});

it('rejects an unsupported courier type', function () {
    expect(fn () => (new CourierAdapterRegistry)->for('EXTERNAL_XYZ'))->toThrow(InvalidArgumentException::class);
});
