<?php
namespace App\Models;use Illuminate\Database\Eloquent\Concerns\HasUuids;use Illuminate\Database\Eloquent\Model;use Illuminate\Database\Eloquent\Relations\BelongsTo;use Illuminate\Database\Eloquent\Relations\HasMany;
final class Carrier extends Model{use HasUuids;protected$fillable=['party_id','cima_code','status','capabilities'];protected function casts():array{return['capabilities'=>'array'];}public function party():BelongsTo{return$this->belongsTo(Party::class);}public function products():HasMany{return$this->hasMany(InsuranceProduct::class);}}
