<?php
namespace App\Models;use Illuminate\Database\Eloquent\Concerns\HasUuids;use Illuminate\Database\Eloquent\Model;
final class ComplianceCase extends Model{use HasUuids;protected$fillable=['tenant_id','case_number','type','subject_type','subject_id','status','severity','owner_id','review_due_on','findings','closed_at','idempotency_key','payload_hash','opened_by','closed_by','closure_code','closure_notes','version'];protected function casts():array{return['findings'=>'array','review_due_on'=>'date','closed_at'=>'datetime'];}}
