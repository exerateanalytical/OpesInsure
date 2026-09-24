<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\Settings;

use App\Application\Settings\PlatformSettings;
use Illuminate\Http\JsonResponse;

/** GET public/support-contacts: contacts the admin maintains in Platform settings. */
final class SupportContactsController
{
    public function __invoke(PlatformSettings $settings): JsonResponse
    {
        return response()->json(['data' => $settings->supportContacts()]);
    }
}
