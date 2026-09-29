<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\RegulatoryCrm;

use App\Filament\Shared\Actions\CarrierOnboardingActions;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/**
 * Insurer onboarding (REQ-SET-002 setup + checklist, REQ-AOM-001 capability profile, claims signing keys): one row per
 * insurer with its setup status, latest capability profile and active signing keys. Opens for GET carriers/{c}/setup
 * (carrier_setup.view), GET carriers/{c}/capability-profiles (capability_profiles.view) or claims.carrier.keys; each
 * action keeps its own API permission.
 */
final class InsurerOnboarding extends RegulatoryCrmPage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-building-2';

    protected static ?int $navigationSort = 60;

    protected static ?string $slug = 'insurer-onboarding';

    protected static array $permissions = ['carrier_setup.view', 'capability_profiles.view', 'claims.carrier.keys'];

    protected static string $screen = 'insurer_onboarding';

    protected static string $group = 'Administration';

    public function table(Table $table): Table
    {
        return $table
            ->records(function (): array {
                if (! $this->ready()) {
                    return [];
                }
                $profiles = DB::table('carrier_capability_profiles')->orderBy('version')->get(['carrier_id', 'version', 'status'])->keyBy('carrier_id');
                $keys = DB::table('claim_carrier_signing_keys')->where('status', 'ACTIVE')->selectRaw('carrier_id, count(*) as n')->groupBy('carrier_id')->pluck('n', 'carrier_id');

                return self::keyed(DB::table('carriers as c')->join('parties as p', 'p.id', '=', 'c.party_id')->leftJoin('carrier_setups as s', 's.carrier_id', '=', 'c.id')
                    ->orderBy('p.display_name')->limit(500)->get(['c.id', 'p.display_name as name', 'c.cima_code', 'c.status', 's.status as setup_status'])
                    ->map(fn ($c) => (array) $c + ['capability_profile' => isset($profiles[$c->id]) ? 'v'.$profiles[$c->id]->version.' '.$profiles[$c->id]->status : null,
                        'active_keys' => (int) ($keys[$c->id] ?? 0)]));
            })
            ->columns([
                self::col('name')->searchable(), self::col('cima_code'), self::col('status')->badge(), self::col('setup_status')->badge(),
                self::col('capability_profile'), self::col('active_keys'),
            ])
            ->recordActions([ActionGroup::make([
                CarrierOnboardingActions::setupOpen(), CarrierOnboardingActions::setupAttest(), CarrierOnboardingActions::setupTransition(),
                CarrierOnboardingActions::capabilityDraft(), CarrierOnboardingActions::signingKeyRegister(), CarrierOnboardingActions::signingKeyRevoke(),
            ])->label(__('regulatory_crm_actions.actions'))->icon('lucide-zap')->button()])
            ->emptyStateHeading(__('regulatory_crm_actions.empty'));
    }
}
