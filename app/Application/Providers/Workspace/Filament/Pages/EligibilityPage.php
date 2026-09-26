<?php

declare(strict_types=1);

namespace App\Application\Providers\Workspace\Filament\Pages;

use App\Interfaces\Http\Errors\ApiProblemException;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Validator;

/**
 * Patient Search + Eligibility Check + Eligibility Result + Benefit Details (Gap-Free spec). The result shows coverage
 * status, network status, failure states and preauth requirement — never medical history. An insurer outage shows
 * INSURER_UNAVAILABLE and nothing is approved.
 */
final class EligibilityPage extends ProviderWorkspacePage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMagnifyingGlass;

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

    public function check(): void
    {
        $d = Validator::make(['member_ref' => $this->member_ref, 'service_code' => $this->service_code, 'policy_id' => $this->policy_id ?: null, 'service_date' => $this->service_date ?: null],
            ['member_ref' => 'required|string|max:120', 'service_code' => 'required|string|max:64', 'policy_id' => 'nullable|uuid', 'service_date' => 'nullable|date'])->validate();
        try {
            $this->result = $this->ws()->checkEligibility($this->tenantId(), $this->user(), $this->scope(), $d);
            $this->state = $this->result['ui_state'] ?? 'SUCCESS';
            $this->stateMessage = $this->state === 'SUCCESS' ? null : ($this->result['message'] ?? null);
        } catch (ApiProblemException $e) {
            $this->state = 'VALIDATION_FAILED';
            $this->stateMessage = $e->getMessage();
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
