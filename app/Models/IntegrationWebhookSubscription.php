<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo,HasMany};
use Illuminate\Support\Facades\Crypt;
final class IntegrationWebhookSubscription extends Model
{
    use HasUuids;
    protected $fillable = ['integration_client_id','event_name','endpoint_encrypted','signing_secret_hash','signing_secret_encrypted','status','consecutive_failures','circuit_state','circuit_opened_at'];
    protected function casts(): array { return ['circuit_opened_at'=>'datetime']; }
    public function client(): BelongsTo { return $this->belongsTo(IntegrationClient::class,'integration_client_id'); }
    public function isCircuitOpen(): bool { return $this->circuit_state === 'OPEN'; }
    public function deliveryAttempts(): HasMany { return $this->hasMany(IntegrationDeliveryAttempt::class); }
    // Written with Crypt::encryptString() (no serialization) — must be read back
    // with decryptString(), not the global decrypt() helper, which unserializes.
    public function endpoint(): string { return Crypt::decryptString($this->endpoint_encrypted); }
    public function signingSecret(): string { return Crypt::decryptString($this->signing_secret_encrypted); }
}
