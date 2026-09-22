<?php
namespace App\Policies;
use App\Models\{RecoveryExercise,User};
final class RecoveryExercisePolicy
{
    public function create(User $user): bool { return $user->hasPermission('releases.recovery-exercises.create'); }
}
