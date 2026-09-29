<?php
namespace App\Filament\Admin\Resources\Memberships\Pages;use App\Filament\Admin\Resources\Memberships\MembershipResource;use Filament\Resources\Pages\ListRecords;final class ListMemberships extends ListRecords{
    use \App\Filament\Shared\Concerns\OpensViewPage;
protected static string$resource=MembershipResource::class;
/** P6 2026-09-29: broker admins invite staff from /broker (InvitationService, no role escalation); hidden elsewhere. */
protected function getHeaderActions():array{return[\App\Filament\Shared\Actions\BrokerServicingActions::inviteStaff()];}}
