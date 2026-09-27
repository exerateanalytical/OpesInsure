@php
    $doc = $getRecord();
    $changes = \App\Models\DocumentStatusChange::with('replacement')->where('document_id', $doc->id)
        ->orWhere('replacement_document_id', $doc->id)->orderByDesc('created_at')->get();
    $users = \App\Models\User::whereIn('id', $changes->pluck('requested_by')->merge($changes->pluck('decided_by'))->filter()->unique())->get()->keyBy('id');
    $name = fn ($id) => $id ? ($users[$id]->name ?? $users[$id]->email ?? $id) : '—';
    $prev = $doc->supersedes_document_id ? \App\Models\Document::find($doc->supersedes_document_id) : null;
    $next = $doc->superseded_by_document_id ? \App\Models\Document::find($doc->superseded_by_document_id) : null;
    $url = fn ($d) => \App\Filament\Admin\Resources\GeneratedDocuments\GeneratedDocumentResource::getUrl('view', ['record' => $d->id]);
@endphp
<div class="space-y-4" data-testid="status-history">
    <dl class="grid grid-cols-1 gap-2 text-sm sm:grid-cols-3">
        <div><dt class="text-gray-500">Current status</dt><dd class="font-semibold">{{ \App\Application\Documents\Engine\DocumentEngine::effectiveStatus($doc) }}</dd></div>
        <div><dt class="text-gray-500">Replaces</dt><dd>@if($prev)<a class="text-primary-600 underline" href="{{ $url($prev) }}">{{ $prev->document_number ?? $prev->verification_code }}</a>@else — @endif</dd></div>
        <div><dt class="text-gray-500">Replaced by</dt><dd>@if($next)<a class="text-primary-600 underline" href="{{ $url($next) }}">{{ $next->document_number ?? $next->verification_code }}</a>@else — @endif</dd></div>
    </dl>
    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead><tr>@foreach (['Requested', 'Action', 'Reason', 'Replacement', 'Status', 'Requested by', 'Decided by', 'Decided', 'Note'] as $h)<th class="border-b px-2 py-1 font-semibold">{{ $h }}</th>@endforeach</tr></thead>
            <tbody>
            @forelse ($changes as $c)
                <tr>
                    <td class="border-b border-gray-100 px-2 py-1">{{ $c->created_at?->format('d/m/Y H:i') }}</td>
                    <td class="border-b border-gray-100 px-2 py-1">{{ $c->action }}</td>
                    <td class="border-b border-gray-100 px-2 py-1">{{ $c->reason }}</td>
                    <td class="border-b border-gray-100 px-2 py-1">{{ $c->replacement?->document_number ?? '—' }}</td>
                    <td class="border-b border-gray-100 px-2 py-1">{{ $c->status }}</td>
                    <td class="border-b border-gray-100 px-2 py-1">{{ $name($c->requested_by) }}</td>
                    <td class="border-b border-gray-100 px-2 py-1">{{ $name($c->decided_by) }}</td>
                    <td class="border-b border-gray-100 px-2 py-1">{{ $c->decided_at?->format('d/m/Y H:i') ?? '—' }}</td>
                    <td class="border-b border-gray-100 px-2 py-1">{{ $c->decision_note ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="9" class="px-2 py-2 text-gray-500">No revocation or replacement requests.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
