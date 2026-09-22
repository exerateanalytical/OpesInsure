<?php
namespace App\Models;use Illuminate\Database\Eloquent\Concerns\HasUuids;use Illuminate\Database\Eloquent\Model;final class FinancialPostingProfile extends Model{use HasUuids;protected$fillable=['tenant_id','event_type','currency','debit_account_id','credit_account_id','status','created_by','approved_by','approved_at'];protected function casts():array{return['approved_at'=>'datetime'];}}
