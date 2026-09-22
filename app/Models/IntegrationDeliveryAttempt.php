<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
final class IntegrationDeliveryAttempt extends Model
{
    use HasUuids;
    protected $fillable = ['integration_webhook_subscription_id','event_id','attempt','status','response_status','failure_reason','next_attempt_at','delivered_at','duration_ms','is_manual_replay','replayed_by'];
    protected function casts(): array { return ['next_attempt_at'=>'datetime','delivered_at'=>'datetime']; }
    public function subscription(): BelongsTo { return $this->belongsTo(IntegrationWebhookSubscription::class,'integration_webhook_subscription_id'); }
}
