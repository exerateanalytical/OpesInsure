<?php
namespace App\Models;use Illuminate\Database\Eloquent\Concerns\HasUuids;use Illuminate\Database\Eloquent\Model;use Illuminate\Database\Eloquent\Relations\BelongsTo;
final class PartyContact extends Model{use HasUuids;protected$fillable=['party_id','type','normalized_value','is_primary','verified_at'];protected function casts():array{return['is_primary'=>'boolean','verified_at'=>'datetime'];}public function party():BelongsTo{return$this->belongsTo(Party::class);}}
