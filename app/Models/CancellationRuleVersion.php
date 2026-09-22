<?php
namespace App\Models;use Illuminate\Database\Eloquent\Concerns\HasUuids;use Illuminate\Database\Eloquent\Model;
final class CancellationRuleVersion extends Model{use HasUuids;protected$fillable=['line_code','version','status','basis','short_rate_basis_points','admin_fee_minor','effective_from','effective_until','created_by','approved_by','approved_at'];protected function casts():array{return['effective_from'=>'date','effective_until'=>'date','approved_at'=>'datetime'];}}
