<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
final class IntegrationClientStatusHistory extends Model
{
    use HasUuids;
    public $timestamps = false;
    protected $fillable = ['integration_client_id','from_status','to_status','reason_code','notes','actor_id','occurred_at'];
    protected function casts(): array { return ['occurred_at'=>'datetime']; }
    public function client(): BelongsTo { return $this->belongsTo(IntegrationClient::class,'integration_client_id'); }
}
