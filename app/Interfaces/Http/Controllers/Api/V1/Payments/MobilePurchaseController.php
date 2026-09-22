<?php
declare(strict_types=1);namespace App\Interfaces\Http\Controllers\Api\V1\Payments;
use App\Application\Payments\MobilePurchaseStatusService;use App\Domain\Tenancy\TenantContext;use Illuminate\Http\{JsonResponse,Request};
final class MobilePurchaseController{
public function status(Request$r,string$proposal,MobilePurchaseStatusService$s):JsonResponse{return response()->json(['data'=>$s->status($proposal,$r->user(),app(TenantContext::class)->id())]);}
}
