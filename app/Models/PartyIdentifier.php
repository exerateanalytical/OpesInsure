<?php
namespace App\Models;use Illuminate\Database\Eloquent\Concerns\HasUuids;use Illuminate\Database\Eloquent\Model;use Illuminate\Database\Eloquent\Relations\BelongsTo;
final class PartyIdentifier extends Model{use HasUuids;protected$fillable=['party_id','type','country_code','value_hash','value_encrypted','masked_value','verified_at','verified_by'];protected$hidden=['value_hash','value_encrypted'];protected function casts():array{return['value_encrypted'=>'encrypted','verified_at'=>'datetime'];}public function party():BelongsTo{return$this->belongsTo(Party::class);}}
