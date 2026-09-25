<?php

declare(strict_types=1);

namespace App\Application\Catalogue\Governance\Models;

use App\Models\InsuranceProduct;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** REQ-PRD-007 — PRE §74 review sub-status + governance attributes of one product version. */
final class ProductGovernance extends Model
{
    protected $table = 'product_governance';

    protected $primaryKey = 'insurance_product_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['insurance_product_id', 'stage', 'owner_user_id', 'target_market', 'prohibited_market', 'next_review_date', 'scheduled_publish_at', 'scheduled_by', 'approval_request_id'];

    protected $attributes = ['stage' => 'DRAFT', 'target_market' => '[]', 'prohibited_market' => '[]'];

    protected function casts(): array
    {
        return ['target_market' => 'array', 'prohibited_market' => 'array', 'next_review_date' => 'date', 'scheduled_publish_at' => 'datetime'];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(InsuranceProduct::class, 'insurance_product_id');
    }
}
