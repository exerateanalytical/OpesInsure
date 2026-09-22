<?php
namespace App\Policies;
use App\Models\{ReleaseCandidate,User};
final class ReleaseCandidatePolicy
{
    public function viewAny(User $user): bool { return $user->hasPermission('releases.view'); }
    public function create(User $user): bool { return $user->hasPermission('releases.create'); }
    public function assess(User $user, ReleaseCandidate $candidate): bool { return $user->hasPermission('releases.assess') && in_array($candidate->status,['DRAFT','ASSESSING'],true); }
    public function certify(User $user, ReleaseCandidate $candidate): bool { return $user->hasPermission('releases.certify') && $candidate->created_by !== $user->getKey(); }
}
