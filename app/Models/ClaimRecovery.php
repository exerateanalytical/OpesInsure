<?php
namespace App\Models;use Illuminate\Database\Eloquent\Concerns\HasUuids;use Illuminate\Database\Eloquent\Model;use Illuminate\Database\Eloquent\Relations\BelongsTo;
final class ClaimRecovery extends Model{use HasUuids;protected$fillable=['claim_id','type','status','counterparty_name','target_amount_minor','recovered_amount_minor','currency','reference','notes','opened_by','closed_at'];protected function casts():array{return['closed_at'=>'datetime'];}public function claim():BelongsTo{return$this->belongsTo(Claim::class);}}
