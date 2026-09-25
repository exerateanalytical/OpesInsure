<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\Forms;

use App\Application\MasterData\InputFieldContract;
use App\Application\MasterData\MobileFormSchemas;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/forms            {data:{contract, forms:[codes]}}
 * GET /api/v1/forms/{form}     {data:{form, version, contract, submit_to, steps, fields}}
 * Selection-first schemas for profile/beneficiaries, KYC, claim FNOL and
 * agent leads (InputFieldContract). Public reference data, no personal data.
 */
final class MobileFormSchemaController
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => ['contract' => InputFieldContract::VERSION, 'forms' => array_keys(MobileFormSchemas::all())]]);
    }

    public function show(string $form): JsonResponse
    {
        $schema = MobileFormSchemas::for($form) ?? abort(404, 'Unknown form.');

        return response()->json(['data' => ['form' => strtolower($form)] + $schema]);
    }
}
