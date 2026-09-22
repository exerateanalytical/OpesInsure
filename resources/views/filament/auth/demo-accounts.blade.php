<div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10 mt-6">
    <p class="text-sm font-medium text-gray-950 dark:text-white mb-3">
        Demo accounts (local only &mdash; one click signs you in)
    </p>

    <div class="grid grid-cols-2 gap-2">
        @foreach ($accounts as $account)
            <a
                href="{{ url('/admin/dev-login/' . $account['email']) }}"
                class="flex items-center justify-center rounded-lg px-3 py-2 text-sm font-medium text-gray-700 ring-1 ring-gray-950/10 transition hover:bg-gray-50 dark:text-gray-200 dark:ring-white/20 dark:hover:bg-white/10"
            >
                {{ $account['label'] }}
            </a>
        @endforeach
    </div>
</div>
