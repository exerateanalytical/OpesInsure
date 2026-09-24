<?php
declare(strict_types=1);namespace App\Interfaces\Http\Controllers\Api\V1\Payments;
use App\Application\Demo\{DemoPersonas,DemoPurchaseSettler};use App\Application\Identity\OwnershipScope;use App\Application\Payments\{FinancialCaseService,PaymentInitiationService,PaymentRequestService};use App\Domain\Tenancy\TenantContext;use App\Models\{PaymentIntentRecord,Proposal,Refund,Tenant};use Illuminate\Http\{JsonResponse,Request};
final class PaymentController{
 public function __construct(private OwnershipScope $own) {}
 /* Customers resolve only payments on their own proposals (audit A1). */
 private function payment(string$id):PaymentIntentRecord{return $this->own->applyVia(PaymentIntentRecord::where('tenant_id',app(TenantContext::class)->id()),request()->user(),'proposal')->findOrFail($id);}
 public function store(Request$r,PaymentRequestService$s,TenantContext$c):JsonResponse{$d=$r->validate(['proposal_id'=>'required|uuid|exists:proposals,id','provider'=>'required|in:fake,maviance,campay,mtn_momo,orange_money','payer_phone_e164'=>['required','regex:/^\+[1-9]\d{7,14}$/'],'idempotency_key'=>'required|string|min:16|max:128']);$p=$this->own->apply(Proposal::where('tenant_id',$c->id()),$r->user())->findOrFail($d['proposal_id']);DemoPersonas::assertProviderAllowed($d['provider'],$r->user());$i=$s->create(Tenant::findOrFail($c->id()),$p,$d,$r->user());return response()->json(['data'=>$i],$i->wasRecentlyCreated?201:200);}
 public function initiate(string$payment,PaymentInitiationService$s):JsonResponse{return response()->json(['data'=>$s->initiate($this->payment($payment))],202);}
 /* A5: the app polls this endpoint while it waits, so a demo persona's payment settles here too. */
 public function show(Request$r,string$payment,DemoPurchaseSettler$demo):JsonResponse{$row=$this->payment($payment);if(config('demo.enabled')&&$row->proposal&&$row->proposal->party_id!==null&&$row->proposal->party_id===$r->user()->party_id){$demo->settleIfDue($row->proposal,$r->user());$row->refresh();}return response()->json(['data'=>$row->load('attempts')]);}
 public function requestRefund(Request$r,string$payment,FinancialCaseService$s):JsonResponse{$d=$r->validate(['amount_minor'=>'required|integer|min:1','reason_code'=>'required|string|max:64','notes'=>'nullable|string|max:2000','idempotency_key'=>'required|string|min:16|max:128']);$refund=$s->requestRefund($this->payment($payment),$d,$r->user());return response()->json(['data'=>$refund],$refund->wasRecentlyCreated?201:200);}
 public function approveRefund(Request$r,string$refund,FinancialCaseService$s):JsonResponse{$row=Refund::where('tenant_id',app(TenantContext::class)->id())->findOrFail($refund);return response()->json(['data'=>$s->approveRefund($row,$r->user())]);}
}
