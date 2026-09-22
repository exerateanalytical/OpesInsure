<?php
namespace App\Policies;
use App\Models\{SecurityFinding,User};
final class SecurityFindingPolicy
{
    public function create(User $user): bool { return $user->hasPermission('releases.security-findings.create'); }
}
