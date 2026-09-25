<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo,HasMany};
final class IntegrationClient extends Model
{
    use HasUuids;
    protected $fillable = ['partner_id','name','client_id','client_secret_hash','oauth_client_id','scopes','allowed_ips','status','rate_limit_per_minute','sandbox_rate_limit_per_minute','environment','certified_at','certified_by','activated_at','revoked_at','revoked_by','revocation_reason','last_used_at'];
    protected function casts(): array { return ['scopes'=>'array','allowed_ips'=>'array','certified_at'=>'datetime','activated_at'=>'datetime','revoked_at'=>'datetime','last_used_at'=>'datetime']; }
    public function partner(): BelongsTo { return $this->belongsTo(Partner::class); }
    public function webhookSubscriptions(): HasMany { return $this->hasMany(IntegrationWebhookSubscription::class); }
    public function statusHistory(): HasMany { return $this->hasMany(IntegrationClientStatusHistory::class); }
    public function externalRecordMappings(): HasMany { return $this->hasMany(ExternalRecordMapping::class); }
    public function hasScope(string $scope): bool { return in_array($scope, $this->scopes ?? [], true) || in_array('*', $this->scopes ?? [], true); }
}
