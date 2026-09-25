<?php
namespace App\Models;use Illuminate\Database\Eloquent\Concerns\HasUuids;use Illuminate\Database\Eloquent\Model;use Illuminate\Database\Eloquent\Relations\BelongsTo;
final class ClaimReserveChange extends Model{use HasUuids;protected$fillable=['claim_id','previous_amount_minor','requested_amount_minor','currency','status','reason_code','reserve_type','notes','requested_by','approved_by','approved_at'];protected function casts():array{return['approved_at'=>'datetime'];}public function claim():BelongsTo{return$this->belongsTo(Claim::class);}}
