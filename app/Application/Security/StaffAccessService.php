<?php

declare(strict_types=1);

namespace App\Application\Security;

use App\Application\Audit\AuditWriter;
use App\Application\Identity\MobileAuthService;
use App\Application\Security\Alerts\SecurityAlerts;
use App\Application\Security\Login\LoginActivityRecorder;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Mobile audit B4: containment of a colleague's account (suspend membership / force re-authentication), audited and alerted. */
final class StaffAccessService
{
    public function __construct(private readonly MobileAuthService $auth, private readonly AuditWriter $audit, private readonly LoginActivityRecorder $timeline, private readonly SecurityAlerts $alerts) {}

    public function suspend(TenantMembership $m, User $actor, string $reason, ?Request $request = null): void
    {
        DB::transaction(function () use ($m, $actor, $reason) {
            $m->update(['status' => 'SUSPENDED']);
            $this->auth->revokeAllSessions($m->user);
            $this->audit->record('staff.security.access_suspended', 'tenant_membership', $m->id, ['user_id' => $m->user_id, 'by' => $actor->id], $reason);
        });
        $this->timeline->recordEvent($m->user, 'ACCESS_SUSPENDED', 'SUCCESS', null, null, $request);
        $this->alerts->send($m->user, 'ACCESS_SUSPENDED', $m->tenant_id);
    }

    public function forceReauth(TenantMembership $m, User $actor, ?string $reason, ?Request $request = null): void
    {
        $this->auth->revokeAllSessions($m->user);
        $this->audit->record('staff.security.reauth_forced', 'tenant_membership', $m->id, ['user_id' => $m->user_id, 'by' => $actor->id], $reason);
        $this->timeline->recordEvent($m->user, 'FORCED_REAUTH', 'SUCCESS', null, null, $request);
        $this->alerts->send($m->user, 'REAUTH_REQUIRED', $m->tenant_id);
    }
}
