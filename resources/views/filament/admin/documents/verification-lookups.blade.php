@php
    $doc = $getRecord();
    $rows = \App\Models\PublicVerificationLookup::where('document_id', $doc->id)->orderByDesc('occurred_at')->limit(50)->get();
@endphp
<div class="overflow-x-auto" data-testid="verification-lookups">
    <p class="mb-2 text-sm text-gray-500">Last 50 public lookups of this document ({{ \App\Models\PublicVerificationLookup::where('document_id', $doc->id)->count() }} in total). Only hashes are stored.</p>
    <table class="w-full text-left text-sm">
        <thead><tr>@foreach (['When', 'Channel', 'Result', 'Token', 'Requester (hash)'] as $h)<th class="border-b px-2 py-1 font-semibold">{{ $h }}</th>@endforeach</tr></thead>
        <tbody>
        @forelse ($rows as $r)
            <tr>
                <td class="border-b border-gray-100 px-2 py-1">{{ $r->occurred_at?->format('d/m/Y H:i') }}</td>
                <td class="border-b border-gray-100 px-2 py-1">{{ $r->channel }}</td>
                <td class="border-b border-gray-100 px-2 py-1">{{ $r->result }}</td>
                <td class="border-b border-gray-100 px-2 py-1">{{ $r->token_presented ? 'yes' : 'no' }}</td>
                <td class="border-b border-gray-100 px-2 py-1 font-mono">{{ substr((string) $r->request_fingerprint_hash, 0, 12) }}…</td>
            </tr>
        @empty
            <tr><td colspan="5" class="px-2 py-2 text-gray-500">No public lookups yet.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
