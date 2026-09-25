<?php

declare(strict_types=1);

namespace App\Application\Demo;

/**
 * REQ-SEC-002 — single answer to "which environment is this and is demo
 * behaviour allowed here?".
 *
 * - demo:seed refuses on APP_ENV=production unless DEMO_ALLOW_IN_PRODUCTION
 *   is explicitly true (an operator decision recorded in the server .env).
 * - The runtime bootstrap exposes the environment so clients can show a
 *   DEMO / STAGING banner; production with demo off shows none.
 */
final class DemoEnvironment
{
    public function name(): string
    {
        return (string) app()->environment();
    }

    public function isProduction(): bool
    {
        return app()->isProduction();
    }

    public function demoEnabled(): bool
    {
        return (bool) config('demo.enabled');
    }

    public function seedingAllowed(): bool
    {
        if (! $this->demoEnabled()) {
            return false;
        }

        return ! $this->isProduction() || (bool) config('demo.allow_in_production');
    }

    /** Why seeding is refused, or null when it is allowed. */
    public function seedingRefusal(): ?string
    {
        if (! $this->demoEnabled()) {
            return 'Demo mode is off (DEMO_MODE_ENABLED=false).';
        }
        if ($this->isProduction() && ! config('demo.allow_in_production')) {
            return 'Refusing to seed demo data in production. Set DEMO_ALLOW_IN_PRODUCTION=true only if this production host is intentionally a demo host.';
        }

        return null;
    }

    /** Banner label for clients: DEMO, STAGING, LOCAL, or null for clean production. */
    public function bannerLabel(): ?string
    {
        if ($this->demoEnabled()) {
            return 'DEMO';
        }

        return match ($this->name()) {
            'production' => null,
            'staging' => 'STAGING',
            'testing' => 'TEST',
            default => strtoupper($this->name()),
        };
    }

    /** @return array{name: string, demo_mode: bool, banner: string|null} */
    public function toArray(): array
    {
        return ['name' => $this->name(), 'demo_mode' => $this->demoEnabled(), 'banner' => $this->bannerLabel()];
    }
}
