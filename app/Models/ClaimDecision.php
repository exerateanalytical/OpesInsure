<?php
namespace App\Models;use Illuminate\Database\Eloquent\Concerns\HasUuids;use Illuminate\Database\Eloquent\Model;use Illuminate\Database\Eloquent\Relations\BelongsTo;
final class ClaimDecision extends Model{use HasUuids;protected$fillable=['claim_id','decision','approved_amount_minor','currency','reason_code','rationale','authority_snapshot','status','proposed_by','approved_by','approved_at'];protected function casts():array{return['authority_snapshot'=>'array','approved_at'=>'datetime'];}public function claim():BelongsTo{return$this->belongsTo(Claim::class);}}
