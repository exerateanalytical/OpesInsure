<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\Claims;

use Illuminate\Foundation\Http\FormRequest;

/** Payload of intermediary-assisted FNOL (agent and broker endpoints). Authorization is the route permission + the book check. */
final class AssistedFnolRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'policy_id' => 'required|uuid',
            'claimant_party_id' => 'required|uuid',
            'loss_occurred_at' => 'required|date|before_or_equal:now',
            'loss_details' => 'required|array',
            'loss_location' => 'nullable|string|max:255',
            'estimated_loss_minor' => 'nullable|integer|min:0',
            'idempotency_key' => 'required|string|min:16|max:128',
        ];
    }
}
