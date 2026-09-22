<?php
declare(strict_types=1);namespace App\Interfaces\Http\Controllers\Api\V1\Payments;
use App\Application\Payments\{FinancialCaseService,PaymentInitiationService,PaymentRequestService};use App\Domain\Tenancy\TenantContext;use App\Models\{PaymentIntentRecord,Proposal,Refund,Tenant};use Illuminate\Http\{JsonResponse,Request};
final class PaymentController{
 private function payment(string$id):PaymentIntentRecord{return PaymentIntentRecord::where('tenant_id',app(TenantContext::class)->id())->findOrFail($id);}
 public function store(Request$r,PaymentRequestService$s,TenantContext$c):JsonResponse{$d=$r->validate(['proposal_id'=>'required|uuid|exists:proposals,id','provider'=>'required|in:fake,maviance,campay,mtn_momo,orange_money','payer_phone_e164'=>['required','regex:/^\+[1-9]\d{7,14}$/'],'idempotency_key'=>'required|string|min:16|max:128']);$p=Proposal::where('tenant_id',$c->id())->findOrFail($d['proposal_id']);$i=$s->create(Tenant::findOrFail($c->id()),$p,$d,$r->user());return response()->json(['data'=>$i],$i->wasRecentlyCreated?201:200);}
 public function initiate(string$payment,PaymentInitiationService$s):JsonResponse{return response()->json(['data'=>$s->initiate($this->payment($payment))],202);}
 public function show(string$payment):JsonResponse{return response()->json(['data'=>$this->payment($payment)->load('attempts')]);}
 public function requestRefund(Request$r,string$payment,FinancialCaseService$s):JsonResponse{$d=$r->validate(['amount_minor'=>'required|integer|min:1','reason_code'=>'required|string|max:64','notes'=>'nullable|string|max:2000','idempotency_key'=>'required|string|min:16|max:128']);$refund=$s->requestRefund($this->payment($payment),$d,$r->user());return response()->json(['data'=>$refund],$refund->wasRecentlyCreated?201:200);}
 public function approveRefund(Request$r,string$refund,FinancialCaseService$s):JsonResponse{$row=Refund::where('tenant_id',app(TenantContext::class)->id())->findOrFail($refund);return response()->json(['data'=>$s->approveRefund($row,$r->user())]);}
}
