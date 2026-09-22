<?php
namespace App\Models;use Illuminate\Database\Eloquent\Concerns\HasUuids;use Illuminate\Database\Eloquent\Model;
final class DocumentRequirementVersion extends Model{use HasUuids;protected$fillable=['line_code','code','version','name','rules','mandatory','status','effective_from','effective_until','created_by','approved_by'];protected function casts():array{return['name'=>'array','rules'=>'array','mandatory'=>'boolean','effective_from'=>'date','effective_until'=>'date'];}}
