<x-filament-panels::page>
    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}

        <div class="flex gap-3">
            <x-filament::button type="submit">Save new version</x-filament::button>
        </div>
        <p class="text-sm text-gray-500">Every save creates a new audited version. Documents already issued keep the version they were issued with. Use "Preview PDF" to see the unsaved letterhead on a specimen document.</p>
    </form>
</x-filament-panels::page>
