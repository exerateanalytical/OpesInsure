<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class SavedComparison extends Model
{
    use HasUuids;
    protected $fillable = ['tenant_id','user_id','quote_request_id','selected_offer_ids','expires_at'];
    protected function casts(): array { return ['selected_offer_ids'=>'array','expires_at'=>'datetime']; }
}
