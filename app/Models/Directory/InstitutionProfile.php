<?php

declare(strict_types=1);

namespace App\Models\Directory;

use App\Models\Carrier;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Institutional directory profile of an official insurer (contacts, website, PO box, sources, verification). */
final class InstitutionProfile extends Model
{
    use HasUuids;

    public const STATUSES = ['VERIFIED', 'PARTIALLY_VERIFIED', 'VERIFIED_HQ_BRANCHES_PENDING', 'VERIFIED_NETWORK_SHARED_WITH_GROUP'];

    protected $fillable = ['carrier_id', 'directory_id', 'directory_name', 'website', 'po_box', 'phones', 'emails', 'sources', 'maps_listing',
        'verification_status', 'verified_at', 'regulatory_reference_note', 'regulatory_reference_status', 'dataset', 'dataset_version', 'admin_edited_at', 'admin_edited_by',
        'partner_id', 'official_sequence', 'locality', 'street_address', 'responsible_person', 'license_reference', 'authorized_year', 'source_url', 'import_batch_id'];

    protected function casts(): array
    {
        return ['phones' => 'array', 'emails' => 'array', 'sources' => 'array', 'maps_listing' => 'array', 'verified_at' => 'date', 'admin_edited_at' => 'datetime'];
    }

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class);
    }

    public function offices(): HasMany
    {
        return $this->hasMany(InstitutionOffice::class, 'carrier_id', 'carrier_id')->orderBy('sort_order');
    }
}
