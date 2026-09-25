<?php

declare(strict_types=1);

namespace App\Application\Authority\Http;

use App\Application\Authority\AuthorityTypeCatalogue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** REQ-AUTH-001 — GET/POST /v1/authority-types, POST /v1/authority-types/{code}/retire (owner decision #12). */
final class AuthorityTypeController
{
    public function __construct(private readonly AuthorityTypeCatalogue $types) {}

    public function index(Request $r): JsonResponse
    {
        return response()->json(['data' => $this->types->all($r->boolean('active'))]);
    }

    public function store(Request $r): JsonResponse
    {
        $d = $r->validate([
            'code' => 'required|string|max:32|regex:/^[A-Z][A-Z0-9_]*$/', 'name' => 'required|string|max:120',
            'description' => 'nullable|string|max:2000', 'monetary' => 'nullable|boolean',
        ]);

        return response()->json(['data' => $this->types->add($d, $r->user())], 201);
    }

    public function retire(Request $r, string $code): JsonResponse
    {
        $d = $r->validate(['reason' => 'required|string|min:5|max:2000']);

        return response()->json(['data' => $this->types->retire($code, $d['reason'])]);
    }
}
