<?php

declare(strict_types=1);

namespace App\Application\Demo;

use App\Application\Settings\PlatformSettings;
use App\Models\PlatformSetting;

/**
 * S13 — the one answer to "is demo mode on?" and "must is_demo rows be hidden right now?".
 *
 * Source of truth: platform_settings.demo_mode_enabled (set by demo:exit / demo:enter). When that column is
 * NULL the legacy .env switch (config demo.enabled) still decides, so nothing changes until an operator acts.
 * AppServiceProvider copies the resolved value into config('demo.enabled') on boot, so every existing reader
 * of that config key (routes, OTP, seeders, settlers, document marks) follows the database decision.
 *
 * While demo mode is OFF, every SELECT whose root table is in TABLES gets "is_demo = false" appended by the
 * query grammar (OffsetAwarePostgresConnection), which covers Eloquent and DB::table alike. Platform admins
 * see demo rows only inside an explicit reveal() (the demo data view, demo:exit's report).
 */
final class DemoMode
{
    /** Tables carrying the is_demo provenance flag that are filtered while demo mode is off. */
    public const TABLES = [
        'tenants', 'tenant_branches', 'parties', 'tenant_customers', 'risk_assets', 'quotes', 'quote_offers', 'proposals',
        'underwriting_cases', 'payment_intents', 'policies', 'policy_issuance_requests', 'claims', 'commission_accruals',
        'partners', 'carrier_broker_agreements',
        // Dependent tables (migration 2026_11_14_100002), back-filled by DemoModeSwitch::flagDerived().
        'claim_documents', 'claim_events', 'claim_payments', 'customer_attributions', 'financial_obligations', 'kyc_submissions',
        'partner_licences', 'partner_statements', 'payment_attempts', 'policy_cancellations', 'policy_premium_instalments',
        'policy_transactions', 'premium_components', 'refunds', 'renewal_cases', 'renewal_work_items', 'settlement_batches',
    ];

    /** @var array<string, true> tables confirmed to carry is_demo (only positives cached: migrations add it later). */
    private static array $flagged = [];

    private static int $revealDepth = 0;

    private ?bool $enabled = null;

    public function __construct(private PlatformSettings $settings)
    {
    }

    /** The stored operator decision: true/false, or null when never set (follow .env). */
    public function stored(): ?bool
    {
        $value = $this->settings->row()?->getAttribute('demo_mode_enabled');

        return $value === null ? null : (bool) $value;
    }

    public function enabled(): bool
    {
        // Only the stored decision is memoised; without one, config (which tests and boot may change) decides live.
        if ($this->enabled === null && ($stored = $this->stored()) !== null) {
            $this->enabled = $stored;
        }

        return $this->enabled ?? (bool) config('demo.enabled');
    }

    /** True when is_demo rows must be left out of the current query. */
    public function hidesDemoData(): bool
    {
        return self::$revealDepth === 0 && ! $this->enabled();
    }

    /**
     * Runs $callback with demo rows visible (platform-admin demo data view, demo:exit / demo:enter).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function reveal(callable $callback): mixed
    {
        self::$revealDepth++;
        try {
            return $callback();
        } finally {
            self::$revealDepth--;
        }
    }

    public function set(bool $enabled, string $actor): void
    {
        $row = $this->settings->editable();
        $row->forceFill(['demo_mode_enabled' => $enabled, 'demo_mode_changed_at' => now(), 'demo_mode_changed_by' => mb_substr($actor, 0, 120)])->save();
        $this->settings->flush();
        $this->forget();
        config(['demo.enabled' => $enabled]);
    }

    /** Boot hook: config('demo.enabled') follows the stored decision when there is one. */
    public function applyToConfig(): void
    {
        $stored = $this->stored();
        if ($stored !== null) {
            config(['demo.enabled' => $stored]);
        }
    }

    public function forget(): void
    {
        $this->enabled = null;
    }

    public static function isFiltered(string $table): bool
    {
        return in_array($table, self::TABLES, true);
    }

    /** isFiltered() and the column really exists (a fresh migrate reads these tables before is_demo is added). */
    public static function isFlagged(string $table): bool
    {
        if (isset(self::$flagged[$table])) {
            return true;
        }
        if (! self::isFiltered($table) || ! \Illuminate\Support\Facades\Schema::hasColumn($table, 'is_demo')) {
            return false;
        }

        return self::$flagged[$table] = true;
    }

    /** @return array{stored: bool|null, enabled: bool, changed_at: string|null, changed_by: string|null} */
    public function status(): array
    {
        /** @var PlatformSetting|null $row */
        $row = $this->settings->row();

        return ['stored' => $this->stored(), 'enabled' => $this->enabled(),
            'changed_at' => $row?->getAttribute('demo_mode_changed_at')?->toIso8601String(), 'changed_by' => $row?->getAttribute('demo_mode_changed_by')];
    }
}
