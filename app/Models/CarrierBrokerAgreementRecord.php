<?php

declare(strict_types=1);

namespace App\Models;

use App\Application\WebExperiences\PortalScope;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};

/**
 * Read model over carrier_broker_agreements for the web screens (owner
 * decision D4). All writes stay in CarrierBrokerAgreementService (maker-checker,
 * product/commission rules, audit); this model is never saved by the UI.
 */
final class CarrierBrokerAgreementRecord extends Model
{
    use HasUuids;

    protected $table = 'carrier_broker_agreements';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['territories' => 'array', 'channels' => 'array', 'effective_from' => 'date', 'effective_until' => 'date', 'approved_at' => 'datetime', 'is_demo' => 'boolean'];
    }

    public function carrier(): BelongsTo { return $this->belongsTo(Carrier::class); }

    public function partner(): BelongsTo { return $this->belongsTo(Partner::class); }

    public function products(): HasMany { return $this->hasMany(CarrierBrokerAgreementProductRecord::class, 'agreement_id'); }

    /**
     * Same boundary as CarrierBrokerAgreementController::index for tenant
     * callers (partner belongs to the tenant), plus — in the insurer portal —
     * agreements signed with the caller's own carrier.
     */
    public function scopeVisibleInPortal(Builder $q): Builder
    {
        $tenant = rescue(fn () => app(TenantContext::class)->id(), null, false);
        $carrier = PortalScope::carrierId();
        if ($tenant === null) {
            return $q->whereRaw('1 = 0');
        }

        return $q->where(fn (Builder $w) => $w
            ->whereIn('partner_id', Partner::query()->where('tenant_id', $tenant)->select('id'))
            ->when($carrier, fn (Builder $w2) => $w2->orWhere('carrier_id', $carrier)));
    }
}
