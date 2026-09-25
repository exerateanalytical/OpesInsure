<?php
namespace App\Application\WebExperiences;

use App\Models\{MarketplacePublication,PortalWorkspace};
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PortalWorkspaceService
{
    public const PORTALS = ['ADMIN','BROKER','CARRIER','AGENT','CUSTOMER'];

    public function touch(?string $tenantId, User $user, string $portal, array $preferences = []): PortalWorkspace
    {
        if (! in_array($portal, self::PORTALS, true)) {
            throw ValidationException::withMessages(['portal' => __('wave10.invalid_portal')]);
        }

        return DB::transaction(fn () => PortalWorkspace::query()->updateOrCreate(
            ['tenant_id'=>$tenantId,'user_id'=>$user->getKey(),'portal'=>$portal],
            ['locale'=>app()->getLocale(),'preferences'=>$preferences,'last_seen_at'=>now()]
        ));
    }

    public function publish(string $tenantId, array $data, User $actor): MarketplacePublication
    {
        return DB::transaction(function () use ($tenantId, $data, $actor): MarketplacePublication {
            return MarketplacePublication::query()->create([
                ...$data,'tenant_id'=>$tenantId,'status'=>'DRAFT','created_by'=>$actor->getKey(),'version'=>1,
            ]);
        });
    }

    public function approve(MarketplacePublication $publication, User $actor, int $expectedVersion): MarketplacePublication
    {
        if ($publication->status !== 'DRAFT' || $publication->version !== $expectedVersion) {
            throw ValidationException::withMessages(['version' => __('wave10.stale_or_invalid')]);
        }
        $publication->forceFill(['status'=>'PUBLISHED','approved_by'=>$actor->getKey(),'approved_at'=>now(),'version'=>$expectedVersion + 1])->save();
        return $publication->refresh();
    }
}
