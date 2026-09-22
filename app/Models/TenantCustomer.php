<?php
namespace App\Models;use Illuminate\Database\Eloquent\Concerns\HasUuids;use Illuminate\Database\Eloquent\Model;use Illuminate\Database\Eloquent\Relations\BelongsTo;
final class TenantCustomer extends Model{use HasUuids;protected $fillable=['tenant_id','party_id','customer_number','status','private_metadata'];protected function casts():array{return['private_metadata'=>'array'];}public function tenant():BelongsTo{return$this->belongsTo(Tenant::class);}public function party():BelongsTo{return$this->belongsTo(Party::class);}}
