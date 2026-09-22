<?php
namespace App\Models;use Illuminate\Database\Eloquent\Concerns\HasUuids;use Illuminate\Database\Eloquent\Model;use Illuminate\Database\Eloquent\Relations\BelongsTo;
final class UnderwritingReferralTask extends Model{use HasUuids;protected$fillable=['underwriting_case_id','reason_code','status','severity','assigned_to','due_at','resolution_notes','resolved_by','resolved_at'];protected function casts():array{return['due_at'=>'datetime','resolved_at'=>'datetime'];}public function underwritingCase():BelongsTo{return$this->belongsTo(UnderwritingCase::class);}}
