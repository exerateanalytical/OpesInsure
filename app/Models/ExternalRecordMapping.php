<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
final class ExternalRecordMapping extends Model
{
    use HasUuids;
    protected $fillable = ['tenant_id','integration_client_id','record_type','external_record_id','opesinsure_record_id','source_of_truth','external_version','opesinsure_version','last_external_modified_at','last_synchronized_at','synchronization_status','conflict_status','metadata'];
    protected function casts(): array { return ['metadata'=>'array','last_external_modified_at'=>'datetime','last_synchronized_at'=>'datetime']; }
    public function client(): BelongsTo { return $this->belongsTo(IntegrationClient::class,'integration_client_id'); }
    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }
}
