<?php

declare(strict_types=1);

namespace App\Application\Integrations\Developer\Http;

use App\Application\Integrations\Developer\Portal\PartnerDeveloperPortalService;
use App\Models\IntegrationClient;
use App\Models\User;
use Illuminate\Http\{JsonResponse,Request};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** S1 — platform staff link partner users to their integration client (entry to the /developers portal). */
final class DeveloperLinksController
{
    public function __construct(private readonly PartnerDeveloperPortalService $svc) {}

    public function index(IntegrationClient $client): JsonResponse
    {
        return response()->json(['data' => DB::table('integration_client_developers as d')->join('users as u', 'u.id', '=', 'd.user_id')
            ->where('d.integration_client_id', $client->id)->orderBy('d.created_at')
            ->get(['d.id', 'd.user_id', 'u.full_name', 'u.email', 'd.role', 'd.status', 'd.created_at', 'd.revoked_at'])]);
    }

    public function store(Request $r, IntegrationClient $client): JsonResponse
    {
        $d = $r->validate(['user_id' => 'required|uuid|exists:users,id', 'role' => ['required', Rule::in(PartnerDeveloperPortalService::ROLES)]]);

        return response()->json(['data' => $this->svc->linkDeveloper($client, User::findOrFail($d['user_id']), $d['role'], $r->user())], 201);
    }

    public function destroy(Request $r, IntegrationClient $client, string $user): JsonResponse
    {
        $this->svc->unlinkDeveloper($client, $user, $r->user());

        return response()->json(['data' => ['user_id' => $user, 'status' => 'REVOKED']]);
    }
}
