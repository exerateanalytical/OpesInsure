<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class MarketplacePublication extends Model
{
    use HasUuids;
    protected $fillable = ['tenant_id','product_id','tariff_version_id','status','channels','starts_at','ends_at','created_by','approved_by','approved_at','version'];
    protected function casts(): array { return ['channels'=>'array','starts_at'=>'datetime','ends_at'=>'datetime','approved_at'=>'datetime']; }
}
