<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\Organization;

use App\Application\Audit\AuditWriter;
use App\Application\Settings\PlatformSettings;
use App\Application\Settings\TimezoneCatalogue;
use App\Application\Tenancy\OrganizationStructureService;
use App\Application\Temporal\TimezoneResolver;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** REQ-TMP-003 — platform / tenant / user timezone settings. */
final class TimezoneSettingsController
{
    public function __construct(private readonly TimezoneCatalogue $catalogue, private readonly TimezoneResolver $resolver, private readonly TenantContext $tenant)
    {
    }

    public function catalogue(): JsonResponse
    {
        return response()->json(['data' => $this->catalogue->all(), 'meta' => ['source' => $this->catalogue->source(), 'platform_default' => $this->resolver->platformDefault()]]);
    }

    public function me(Request $r): JsonResponse
    {
        return response()->json(['data' => $this->userPayload($r)]);
    }

    public function updateMe(Request $r, AuditWriter $audit): JsonResponse
    {
        $d = $r->validate(['display_timezone' => ['present', 'nullable', 'string', 'max:64', $this->rule()]]);
        $user = $r->user();
        $previous = $user->display_timezone;
        $user->forceFill(['display_timezone' => $d['display_timezone']])->save();
        $audit->record('user.display_timezone.changed', 'user', $user->id, ['from' => $previous, 'to' => $d['display_timezone']]);

        return response()->json(['data' => $this->userPayload($r)]);
    }

    public function organization(): JsonResponse
    {
        return response()->json(['data' => $this->resolver->explain($this->tenant->id())]);
    }

    public function updateOrganization(Request $r, OrganizationStructureService $org): JsonResponse
    {
        $d = $r->validate(['timezone' => ['present', 'nullable', 'string', 'max:64', $this->rule()]]);
        $org->setTenantTimezone($this->tenant->id(), $d['timezone'], $r->user()->id);

        return response()->json(['data' => $this->resolver->explain($this->tenant->id())]);
    }

    public function platform(): JsonResponse
    {
        return response()->json(['data' => [
            'default_timezone' => $this->resolver->platformDefault(),
            'configured' => app(PlatformSettings::class)->get('default_timezone'),
            'fallback' => (string) config('app.timezone'),
        ]]);
    }

    public function updatePlatform(Request $r, PlatformSettings $settings, AuditWriter $audit): JsonResponse
    {
        abort_unless(DB::table('tenants')->where('id', $this->tenant->id())->value('type') === 'PLATFORM', 403, 'Platform settings are managed from the platform tenant.');
        $d = $r->validate(['default_timezone' => ['present', 'nullable', 'string', 'max:64', $this->rule()]]);
        $row = $settings->editable();
        $previous = $row->default_timezone;
        $row->default_timezone = $d['default_timezone'];
        $row->save();
        $settings->flush();
        $this->resolver->forget();
        $audit->record('platform.default_timezone.changed', 'platform_setting', null, ['platform_setting_id' => $row->id, 'from' => $previous, 'to' => $d['default_timezone']]);

        return $this->platform();
    }

    private function rule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if ($value !== null && ! $this->catalogue->isValid($value)) {
                $fail('The :attribute must be an IANA timezone from master data geography.');
            }
        };
    }

    /** @return array<string, mixed> */
    private function userPayload(Request $r): array
    {
        $user = $r->user()->fresh();
        $business = $this->resolver->current();

        return [
            'display_timezone' => $user->display_timezone,
            'business_timezone' => $business,
            'effective_display_timezone' => TimezoneResolver::valid($user->display_timezone) ?? $business,
        ];
    }
}
