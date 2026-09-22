<?php
namespace App\Interfaces\Http\Controllers\Api\V1\Release;

use App\Application\Release\ReleaseCertificationService;
use App\Domain\Release\ReleaseGate;
use App\Interfaces\Http\Controllers\Concerns\AuthorizesSensitiveActions;
use App\Models\{RecoveryExercise,ReleaseCandidate,SecurityFinding};
use Illuminate\Http\{JsonResponse,Request};

final class Wave11Controller
{
    use AuthorizesSensitiveActions;

    public function create(Request $request, ReleaseCertificationService $service): JsonResponse
    {
        $this->gateAuthorize('create', ReleaseCandidate::class, 'release_candidate');
        $data=$request->validate(['version'=>'required|string|max:64','commit_sha'=>'required|string|size:40','environment'=>'required|in:staging,production']);
        $candidate=$this->auditedCall(fn()=>$service->create($data,$request->user()),'releases.candidates.create','release_candidate',null);
        return response()->json($candidate,201);
    }
    public function gate(Request $request, ReleaseCandidate $candidate, ReleaseCertificationService $service): JsonResponse
    {
        $this->gateAuthorize('assess', $candidate, 'release_candidate', $candidate->getKey());
        $data=$request->validate(['gate'=>'required|in:SECURITY,PERFORMANCE,ACCESSIBILITY,DISASTER_RECOVERY,UAT,OPENAPI,DATA_MIGRATION','status'=>'required|in:PASS,FAIL','evidence'=>'required|array|min:1']);
        return response()->json($this->auditedCall(fn()=>$service->record($candidate,ReleaseGate::from($data['gate']),$data['status'],$data['evidence'],$request->user()),'releases.candidates.gate','release_candidate',$candidate->getKey()));
    }
    public function certify(Request $request, ReleaseCandidate $candidate, ReleaseCertificationService $service): JsonResponse
    {
        $this->gateAuthorize('certify', $candidate, 'release_candidate', $candidate->getKey());
        $data=$request->validate(['expected_version'=>'required|integer|min:1']);
        return response()->json($this->auditedCall(fn()=>$service->certify($candidate,$request->user(),$data['expected_version']),'releases.candidates.certify','release_candidate',$candidate->getKey()));
    }
    public function finding(Request $request): JsonResponse
    {
        $this->gateAuthorize('create', SecurityFinding::class, 'security_finding');
        $data=$request->validate(['release_candidate_id'=>'nullable|uuid','source'=>'required|string|max:32','severity'=>'required|in:LOW,MEDIUM,HIGH,CRITICAL','title'=>'required|string|max:255','description'=>'required|string|max:8000','cve'=>'nullable|string|max:32','owner_id'=>'nullable|uuid','due_at'=>'nullable|date']);
        $finding=$this->auditedCall(fn()=>SecurityFinding::query()->create($data),'releases.security-findings.create','security_finding',null);
        return response()->json($finding,201);
    }
    public function recovery(Request $request): JsonResponse
    {
        $this->gateAuthorize('create', RecoveryExercise::class, 'recovery_exercise');
        $data=$request->validate(['environment'=>'required|in:staging,production','exercise_type'=>'required|in:BACKUP_RESTORE,REGION_FAILOVER,TABLETOP','target_rto_minutes'=>'required|integer|min:1','target_rpo_minutes'=>'required|integer|min:0']);
        $exercise=$this->auditedCall(fn()=>RecoveryExercise::query()->create([...$data,'status'=>'PLANNED']),'releases.recovery-exercises.create','recovery_exercise',null);
        return response()->json($exercise,201);
    }
}
