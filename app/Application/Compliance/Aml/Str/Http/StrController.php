<?php

declare(strict_types=1);

namespace App\Application\Compliance\Aml\Str\Http;

use App\Application\Compliance\Aml\Str\StrService;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** REQ-AML-003 — STR endpoints; every call is re-authorised by StrService (cases.str.view, else 404). */
final class StrController
{
    public function __construct(private readonly StrService $str) {}

    public function index(Request $r): JsonResponse
    {
        return response()->json(['data' => $this->str->index($this->tenant(), $r->user())]);
    }

    public function store(Request $r): JsonResponse
    {
        $d = $r->validate(['party_id' => 'nullable|uuid|exists:parties,id', 'grounds' => 'required|string|min:20|max:8000',
            'related_alert_ids' => 'nullable|array|max:50', 'related_alert_ids.*' => 'uuid']);

        return response()->json(['data' => $this->str->draft($this->tenant(), $d, $r->user())], 201);
    }

    public function show(Request $r, string $str): JsonResponse
    {
        return response()->json(['data' => $this->str->show($this->tenant(), $str, $r->user())]);
    }

    public function submit(Request $r, string $str): JsonResponse
    {
        $d = $r->validate(['regulator_reference' => 'required|string|max:120']);

        return response()->json(['data' => $this->str->submit($this->tenant(), $str, $d['regulator_reference'], $r->user())]);
    }

    private function tenant(): string
    {
        return (string) app(TenantContext::class)->id();
    }
}
