<?php
namespace App\Application\Release;

use App\Domain\Release\ReleaseGate;
use App\Models\{ReleaseCandidate,ReleaseGateResult,SecurityFinding};
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ReleaseCertificationService
{
    public function create(array $data, User $actor): ReleaseCandidate
    {
        return ReleaseCandidate::query()->create([...$data,'status'=>'DRAFT','created_by'=>$actor->getKey(),'lock_version'=>1]);
    }

    public function record(ReleaseCandidate $candidate, ReleaseGate $gate, string $status, array $evidence, User $actor): ReleaseGateResult
    {
        if (! in_array($candidate->status, ['DRAFT','ASSESSING'], true)) throw ValidationException::withMessages(['status'=>__('wave11.locked')]);
        $canonical = json_encode($evidence, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $candidate->forceFill(['status'=>'ASSESSING'])->save();
        return ReleaseGateResult::query()->updateOrCreate(
            ['release_candidate_id'=>$candidate->getKey(),'gate'=>$gate->value],
            ['status'=>$status,'evidence'=>$evidence,'evidence_hash'=>hash('sha256',$canonical),'assessed_by'=>$actor->getKey(),'assessed_at'=>now()]
        );
    }

    public function certify(ReleaseCandidate $candidate, User $actor, int $expectedVersion): ReleaseCandidate
    {
        return DB::transaction(function () use ($candidate, $actor, $expectedVersion): ReleaseCandidate {
            $candidate->refresh();
            if ($candidate->lock_version !== $expectedVersion || $candidate->created_by === $actor->getKey()) throw ValidationException::withMessages(['approval'=>__('wave11.maker_checker')]);
            $required = collect(ReleaseGate::cases())->pluck('value');
            $passed = ReleaseGateResult::query()->where('release_candidate_id',$candidate->getKey())->where('status','PASS')->pluck('gate');
            $critical = SecurityFinding::query()->where('release_candidate_id',$candidate->getKey())->whereIn('severity',['CRITICAL','HIGH'])->where('status','!=','RESOLVED')->exists();
            if ($required->diff($passed)->isNotEmpty() || $critical) throw ValidationException::withMessages(['gates'=>__('wave11.gates_failed')]);
            $candidate->forceFill(['status'=>'CERTIFIED','approved_by'=>$actor->getKey(),'approved_at'=>now(),'lock_version'=>$expectedVersion+1])->save();
            return $candidate->refresh();
        });
    }
}
