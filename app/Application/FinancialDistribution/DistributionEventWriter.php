<?php
declare(strict_types=1); namespace App\Application\FinancialDistribution;
use App\Models\User; use Illuminate\Support\Facades\DB; use Illuminate\Support\Str;
final class DistributionEventWriter { public function write(?string $tenantId,string $type,string $id,?string $from,string $to,string $reason,?User $actor,array $metadata=[]):void { DB::table('financial_distribution_events')->insert(['id'=>(string)Str::uuid(),'tenant_id'=>$tenantId,'aggregate_type'=>$type,'aggregate_id'=>$id,'from_status'=>$from,'to_status'=>$to,'reason_code'=>$reason,'actor_id'=>$actor?->id,'metadata'=>json_encode($metadata,JSON_THROW_ON_ERROR),'occurred_at'=>now()]); } }
