<?php
namespace App\Models;use Illuminate\Database\Eloquent\Concerns\HasUuids;use Illuminate\Database\Eloquent\Model;
final class RiskAlert extends Model{use HasUuids;protected$fillable=['tenant_id','subject_type','subject_id','alert_type','risk_score','severity','status','signals','assigned_to','decided_by','decision','decision_notes','decided_at','fraud_rule_version_id','idempotency_key','payload_hash','review_due_at','version'];protected function casts():array{return['signals'=>'array','decided_at'=>'datetime','review_due_at'=>'datetime'];}}
