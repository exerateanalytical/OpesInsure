<?php
declare(strict_types=1);
namespace App\Interfaces\Http\Controllers\Api\V1\Underwriting;
use App\Application\Underwriting\{ProposalService,UnderwritingService};
use App\Domain\Tenancy\TenantContext;
use App\Models\{Document,Proposal,QuoteOffer,Tenant,UnderwritingCase,UnderwritingReferralTask,User};
use Illuminate\Http\{JsonResponse,Request};
final class ProposalController
{
 private function proposal(string$id):Proposal{return Proposal::where('tenant_id',app(TenantContext::class)->id())->findOrFail($id);}
 private function underwritingCase(string$id):UnderwritingCase{return UnderwritingCase::where('tenant_id',app(TenantContext::class)->id())->findOrFail($id);}
 private function referral(string$id):UnderwritingReferralTask{return UnderwritingReferralTask::whereHas('underwritingCase',fn($q)=>$q->where('tenant_id',app(TenantContext::class)->id()))->findOrFail($id);}
 public function store(Request$r,ProposalService$s,TenantContext$c):JsonResponse{$d=$r->validate(['quote_offer_id'=>'required|uuid|exists:quote_offers,id','party_id'=>'required|uuid|exists:parties,id']);return response()->json(['data'=>$s->create(Tenant::findOrFail($c->id()),QuoteOffer::findOrFail($d['quote_offer_id']),$d,$r->user())],201);}
 public function show(string$proposal):JsonResponse{return response()->json(['data'=>$this->proposal($proposal)->load(['offer.product','disclosureSchema','disclosureResponse','documents.document','underwritingCase.referrals','underwritingCase.decisions','payments'])]);}
 public function answer(Request$r,string$proposal,ProposalService$s):JsonResponse{$d=$r->validate(['answers'=>'required|array']);return response()->json(['data'=>$s->answer($this->proposal($proposal),$d['answers'],$r->user())]);}
 public function attest(Request$r,string$proposal,ProposalService$s):JsonResponse{return response()->json(['data'=>$s->attest($this->proposal($proposal),$r->user())]);}
 public function attachDocument(Request$r,string$proposal,ProposalService$s):JsonResponse{$d=$r->validate(['document_id'=>'required|uuid|exists:documents,id','requirement_code'=>'required|string|max:64']);return response()->json(['data'=>$s->attachDocument($this->proposal($proposal),Document::findOrFail($d['document_id']),$d['requirement_code'])],201);}
 public function reviewDocument(Request$r,string$proposal,string$document,ProposalService$s):JsonResponse{$d=$r->validate(['decision'=>'required|in:VERIFIED,REJECTED','notes'=>'required|string|max:2000']);return response()->json(['data'=>$s->verifyDocument($this->proposal($proposal),Document::findOrFail($document),$d['decision'],$d['notes'],$r->user())]);}
 public function submit(Request$r,string$proposal,ProposalService$s):JsonResponse{return response()->json(['data'=>$s->submit($this->proposal($proposal),$r->user())],202);}
 public function assign(Request$r,string$case,UnderwritingService$s):JsonResponse{$d=$r->validate(['assignee_id'=>'required|uuid|exists:users,id']);return response()->json(['data'=>$s->assign($this->underwritingCase($case),User::findOrFail($d['assignee_id']),$r->user())]);}
 public function resolveReferral(Request$r,string$referral,UnderwritingService$s):JsonResponse{$d=$r->validate(['notes'=>'required|string|min:20|max:4000']);return response()->json(['data'=>$s->resolveReferral($this->referral($referral),$d['notes'],$r->user())]);}
 public function decide(Request$r,string$case,UnderwritingService$s):JsonResponse{$d=$r->validate(['decision'=>'required|in:APPROVED,COUNTEROFFERED,DECLINED','reason_code'=>'required|string|max:64','notes'=>'required|string|min:20|max:4000','conditions'=>'sometimes|array']);return response()->json(['data'=>$s->decide($this->underwritingCase($case),$d,$r->user())]);}
}
