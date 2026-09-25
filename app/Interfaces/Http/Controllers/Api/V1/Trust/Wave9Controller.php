<?php
namespace App\Interfaces\Http\Controllers\Api\V1\Trust;use App\Application\Compliance\RegulatoryReportingService;use App\Domain\Tenancy\TenantContext;use App\Interfaces\Http\Controllers\Concerns\AuthorizesSensitiveActions;use App\Models\{RegulatoryReportDefinition,RegulatoryReportRun};use Illuminate\Http\{JsonResponse,Request};
/** Regulatory report runs only. The fraud-alert, compliance-case, DSR and privileged-access actions moved to their canonical controllers (REQ-DUP-009); trust/* routes for them are deprecated aliases. */
final class Wave9Controller{use AuthorizesSensitiveActions;private function tenant():string{return app(TenantContext::class)->id();}private function owns(object$x):void{if(isset($x->tenant_id)&&$x->tenant_id!==$this->tenant())abort(404);}

public function prepareReport(Request$r,RegulatoryReportDefinition$d,RegulatoryReportingService$s):JsonResponse{$this->authorizePermission('trust.regulatory-reports.prepare','regulatory_report_run');$in=$r->validate(['period_key'=>'required|string|max:40','payload'=>'required|array','idempotency_key'=>'required|string|max:100']);return response()->json($this->auditedCall(fn()=>$s->prepare($this->tenant(),$d,$in,$r->user()),'trust.regulatory-reports.prepare','regulatory_report_run',null),201);}

public function approveReport(Request$r,RegulatoryReportRun$x,RegulatoryReportingService$s):JsonResponse{$this->authorizePermission('trust.regulatory-reports.approve','regulatory_report_run',$x->id);$this->owns($x);return response()->json($this->auditedCall(fn()=>$s->approve($x,$r->user()),'trust.regulatory-reports.approve','regulatory_report_run',$x->id));}

public function submitReport(Request$r,RegulatoryReportRun$x,RegulatoryReportingService$s):JsonResponse{$this->authorizePermission('trust.regulatory-reports.submit','regulatory_report_run',$x->id);$this->owns($x);return response()->json($this->auditedCall(fn()=>$s->submit($x),'trust.regulatory-reports.submit','regulatory_report_run',$x->id));}

public function reportFailure(Request$r,RegulatoryReportRun$x,RegulatoryReportingService$s):JsonResponse{$this->authorizePermission('trust.regulatory-reports.submit','regulatory_report_run',$x->id);$this->owns($x);$d=$r->validate(['reason'=>'required|string|max:2000']);return response()->json($this->auditedCall(fn()=>$s->fail($x,$d['reason']),'trust.regulatory-reports.submit','regulatory_report_run',$x->id));}

public function acknowledgeReport(Request$r,RegulatoryReportRun$x,RegulatoryReportingService$s):JsonResponse{$this->authorizePermission('trust.regulatory-reports.acknowledge','regulatory_report_run',$x->id);$this->owns($x);$d=$r->validate(['external_reference'=>'required|string|max:255']);return response()->json($this->auditedCall(fn()=>$s->acknowledge($x,$d['external_reference']),'trust.regulatory-reports.acknowledge','regulatory_report_run',$x->id));}
}
