<?php
namespace App\Models;use Illuminate\Database\Eloquent\Concerns\HasUuids;use Illuminate\Database\Eloquent\Model;use Illuminate\Database\Eloquent\Relations\BelongsTo;
final class ConsentEvent extends Model{use HasUuids;public$timestamps=false;protected$fillable=['consent_id','from_status','to_status','reason_code','actor_id','evidence','evidence_hash','occurred_at'];protected function casts():array{return['evidence'=>'array','occurred_at'=>'datetime'];}public function consent():BelongsTo{return$this->belongsTo(Consent::class);}}
