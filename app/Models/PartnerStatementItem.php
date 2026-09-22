<?php
namespace App\Models; use Illuminate\Database\Eloquent\Concerns\HasUuids; use Illuminate\Database\Eloquent\Model;
final class PartnerStatementItem extends Model { use HasUuids; public $timestamps=false; protected $fillable=['partner_statement_id','commission_accrual_id','entry_type','reference_type','reference_id','amount_minor','currency','occurred_at','metadata']; protected function casts():array{return['occurred_at'=>'datetime','metadata'=>'array'];} }
