<?php
namespace App\Models;use Illuminate\Database\Eloquent\Concerns\HasUuids;use Illuminate\Database\Eloquent\Model;
final class RegulatoryReportDefinition extends Model{use HasUuids;protected$fillable=['code','version','status','jurisdiction','report_type','schema','schema_hash','effective_from','effective_until','created_by','approved_by','approved_at','regime','regulatory_category_kind','regulatory_category_code','description'];protected function casts():array{return['schema'=>'array','effective_from'=>'date','effective_until'=>'date','approved_at'=>'datetime'];}}
