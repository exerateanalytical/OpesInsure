<?php
namespace App\Models;use Illuminate\Database\Eloquent\Concerns\HasUuids;use Illuminate\Database\Eloquent\Model;use Illuminate\Database\Eloquent\Relations\BelongsTo;
final class UnderwritingDecision extends Model{use HasUuids;protected$fillable=['underwriting_case_id','decision','reason_code','notes','conditions','decided_by','decided_at'];protected function casts():array{return['conditions'=>'array','decided_at'=>'datetime'];}public function underwritingCase():BelongsTo{return$this->belongsTo(UnderwritingCase::class);}}
