<x-filament-panels::page>
    @if ($canManage)
        <x-filament::section :heading="__('bulk_agreements.upload.heading')" :description="__('bulk_agreements.upload.help')">
            <div class="text-xs text-gray-500 mb-2">{{ __('bulk_agreements.upload.columns') }}: <code>{{ implode(', ', $columns) }}</code></div>
            <form wire:submit="preview" class="flex flex-wrap items-center gap-3">
                <input type="file" wire:model="upload" accept=".csv,.xlsx,.txt" class="text-sm">
                <x-filament::button type="submit">{{ __('bulk_agreements.upload.preview') }}</x-filament::button>
            </form>
        </x-filament::section>
    @endif

    @if ($results !== [])
        <x-filament::section :heading="__('bulk_agreements.preview.heading', ['file' => $fileName])">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead><tr class="text-left"><th class="p-2">{{ __('bulk_agreements.preview.row') }}</th><th class="p-2">{{ __('bulk_agreements.preview.pair') }}</th><th class="p-2">{{ __('bulk_agreements.preview.result') }}</th></tr></thead>
                    <tbody>
                    @foreach ($results as $r)
                        <tr class="border-t border-gray-200 dark:border-white/10">
                            <td class="p-2">{{ $r['row'] }}</td>
                            <td class="p-2">{{ $r['label'] }}</td>
                            <td class="p-2">
                                @if ($r['errors'] === [])
                                    <span class="text-success-600">{{ __('bulk_agreements.preview.ok', ['lines' => count($r['data']['lines'])]) }}</span>
                                @else
                                    <ul class="text-danger-600">@foreach ($r['errors'] as $e)<li>{{ $e }}</li>@endforeach</ul>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-3">
                @if ($hasErrors)
                    <span class="text-sm text-danger-600">{{ __('bulk_agreements.errors.fix_first') }}</span>
                @else
                    <x-filament::button wire:click="createDrafts">{{ __('bulk_agreements.preview.create') }}</x-filament::button>
                @endif
            </div>
        </x-filament::section>
    @endif

    <x-filament::section :heading="__('bulk_agreements.batches.heading')">
        @if ($canApprove)
            <div class="mb-3 flex items-center gap-2">
                <label for="bulk-reason" class="text-sm">{{ __('bulk_agreements.batches.reason') }}</label>
                <input id="bulk-reason" type="text" wire:model="reason" class="rounded-lg border-gray-300 text-sm w-80 dark:bg-white/5">
            </div>
        @endif
        @forelse ($batches as $b)
            <div class="flex flex-wrap items-center gap-3 border-t border-gray-200 py-2 text-sm dark:border-white/10">
                <span>{{ $b->file_name ?? '—' }}</span>
                <span>{{ __('bulk_agreements.batches.count', ['count' => count($b->agreement_ids)]) }}</span>
                <x-filament::badge>{{ __('bulk_agreements.status.'.$b->status) }}</x-filament::badge>
                @if ($b->status === 'DRAFT' && $canManage)
                    <x-filament::button size="sm" wire:click="submitBatch('{{ $b->id }}')">{{ __('bulk_agreements.batches.submit') }}</x-filament::button>
                @endif
                @if (in_array($b->status, ['SUBMITTED', 'PARTIAL'], true) && $canApprove)
                    @if ($b->created_by === $me || $b->submitted_by === $me)
                        <span class="text-xs text-gray-500">{{ __('bulk_agreements.errors.maker_checker') }}</span>
                    @else
                        <x-filament::button size="sm" color="success" wire:click="activateBatch('{{ $b->id }}')">{{ __('bulk_agreements.batches.activate') }}</x-filament::button>
                    @endif
                @endif
                @foreach ($b->activation_errors as $e)
                    <div class="w-full text-xs text-danger-600">{{ $e }}</div>
                @endforeach
            </div>
        @empty
            <div class="text-sm text-gray-500">{{ __('bulk_agreements.batches.none') }}</div>
        @endforelse
    </x-filament::section>
</x-filament-panels::page>
