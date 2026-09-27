<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\Bordereaux\BordereauResource;

it('the bordereaux resource lives at /{panel}/bordereaux', function () {
    expect(BordereauResource::getSlug())->toBe('bordereaux');
});

it('redirects the old /bordereaux/bordereaus URLs', function (string $panel) {
    $this->get("/{$panel}/bordereaux/bordereaus")->assertRedirect("/{$panel}/bordereaux")->assertStatus(301);
    $this->get("/{$panel}/bordereaux/bordereaus/abc")->assertRedirect("/{$panel}/bordereaux/abc");
})->with(['admin', 'broker', 'insurer']);
