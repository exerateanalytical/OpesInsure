<?php
declare(strict_types=1);
namespace App\Interfaces\Http\Controllers\Api\V1;
use App\Application\Quotes\QuoteService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
final class QuoteController
{
    public function store(Request $request,QuoteService $service,TenantContext $context):JsonResponse
    {
        $data=$request->validate(['customer_id'=>'required|uuid','line_code'=>'required|string|max:32','risk_asset_id'=>'nullable|uuid','channel'=>'required|in:B2C,AGENT,BROKER','risk_facts'=>'required|array']);
        $quote=$service->submit(Tenant::findOrFail($context->id()),$data['customer_id'],$data,$request->user());
        return response()->json(['data'=>$quote],202);
    }
}
