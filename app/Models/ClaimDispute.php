<?php
namespace App\Models;use Illuminate\Database\Eloquent\Concerns\HasUuids;use Illuminate\Database\Eloquent\Model;use Illuminate\Database\Eloquent\Relations\BelongsTo;
final class ClaimDispute extends Model{use HasUuids;protected$fillable=['claim_id','reference','status','reason_code','statement','opened_by','resolved_by','resolution','resolved_at'];protected function casts():array{return['resolved_at'=>'datetime'];}public function claim():BelongsTo{return$this->belongsTo(Claim::class);}}
