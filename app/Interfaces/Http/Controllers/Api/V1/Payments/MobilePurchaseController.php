<?php
declare(strict_types=1);namespace App\Interfaces\Http\Controllers\Api\V1\Payments;
use App\Application\Payments\MobilePurchaseStatusService;use App\Domain\Tenancy\TenantContext;use Illuminate\Http\{JsonResponse,Request};
final class MobilePurchaseController{
public function status(Request$r,string$proposal,MobilePurchaseStatusService$s,\App\Application\Demo\DemoPurchaseSettler$demo):JsonResponse{if(config('demo.enabled')&&($p=\App\Models\Proposal::where('tenant_id',app(TenantContext::class)->id())->find($proposal))&&$p->party_id===$r->user()->party_id)$demo->settleIfDue($p,$r->user());return response()->json(['data'=>$s->status($proposal,$r->user(),app(TenantContext::class)->id())]);}
}
