<?php
namespace App\Models; use Illuminate\Database\Eloquent\Concerns\HasUuids; use Illuminate\Database\Eloquent\Model;
final class SettlementItem extends Model { use HasUuids; protected $fillable=['settlement_batch_id','policy_id','payment_intent_id','gross_premium_minor','commission_minor','tax_minor','adjustment_minor','net_due_minor','currency','status','financial_obligation_id','collection_mode']; }
