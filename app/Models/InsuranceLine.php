<?php
namespace App\Models;use Illuminate\Database\Eloquent\Concerns\HasUuids;use Illuminate\Database\Eloquent\Model;use Illuminate\Database\Eloquent\Relations\HasMany;
final class InsuranceLine extends Model{use HasUuids;protected$fillable=['code','name','description','status','risk_schema'];protected function casts():array{return['name'=>'array','description'=>'array','risk_schema'=>'array'];}public function coverages():HasMany{return$this->hasMany(CoverageDefinition::class);}public function exclusions():HasMany{return$this->hasMany(ExclusionDefinition::class);}}
