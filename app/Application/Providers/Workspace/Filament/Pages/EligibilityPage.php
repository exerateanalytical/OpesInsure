<?php

declare(strict_types=1);

namespace App\Application\Providers\Workspace\Filament\Pages;

use BackedEnum;

/**
 * Patient Search + Eligibility Check + Eligibility Result + Benefit Details (Gap-Free spec). The result shows coverage
 * status, network status, failure states and preauth requirement — never medical history. An insurer outage shows
 * INSURER_UNAVAILABLE and nothing is approved.
 */
final class EligibilityPage extends ProviderWorkspacePage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-search';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'eligibility';

    protected static string $permission = 'provider.eligibility.check';

    protected static string $screen = 'eligibility_check';

    public string $member_ref = '';

    public string $service_code = '';

    public ?string $policy_id = null;

    public ?string $service_date = null;

    public array $result = [];

    public function extraView(): ?string
    {
        return 'provider-workspace.eligibility-form';
    }

    /** Member lookup or health-card scan (QR_CODE / HEALTH_ID); one of ProviderWorkspaceRegister::SEARCH_METHODS. */
    public ?string $search_method = 'MEMBERSHIP_NUMBER';

    /** POST provider-portal/eligibility/check through the API controller action (same validation, scope and audit). */
    public function check(): void
    {
        $res = $this->callWorkspace('eligibilityCheck', array_filter(['member_ref' => $this->member_ref, 'search_method' => $this->search_method,
            'service_code' => $this->service_code, 'policy_id' => $this->policy_id ?: null, 'service_date' => $this->service_date ?: null]));
        if ($res !== null) {
            $this->result = $res;
            $this->state = $this->result['ui_state'] ?? 'SUCCESS';
            $this->stateMessage = $this->state === 'SUCCESS' ? null : ($this->result['message'] ?? null);
        }
    }

    protected function rows(): array
    {
        if ($this->result === []) {
            return [];
        }
        $r = $this->result;

        return [['verification_reference' => $r['verification_reference'] ?? null, 'coverage_status' => $r['coverage_status'] ?? null,
            'provider_network_status' => $r['provider_network_status'] ?? null, 'failure_states' => implode(', ', $r['failure_states'] ?? []),
            'preauthorization_required' => ($r['preauthorization_required'] ?? false) ? 'YES' : 'NO', 'benefit_code' => $r['benefit_code'] ?? null,
            'effective_from' => $r['effective_from'] ?? null, 'effective_until' => $r['effective_until'] ?? null]];
    }
}
