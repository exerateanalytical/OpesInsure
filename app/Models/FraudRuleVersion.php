<?php
namespace App\Models;use Illuminate\Database\Eloquent\Concerns\HasUuids;use Illuminate\Database\Eloquent\Model;
final class FraudRuleVersion extends Model{use HasUuids;protected$fillable=['code','version','scope','status','risk_points','conditions','rule_hash','effective_from','effective_until','created_by','approved_by','approved_at','retirement_reason'];protected function casts():array{return['conditions'=>'array','effective_from'=>'date','effective_until'=>'date','approved_at'=>'datetime'];}}
