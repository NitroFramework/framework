@php
    /** @var \Nitro\Database\Query\Paginator $paginator */
    $current = $paginator->currentPage();
    $last = $paginator->lastPage();
    $start = max(1, $current - 2);
    $end = min($last, $current + 2);
@endphp

@if ($paginator->hasPages())
    <nav class="mt-4 flex items-center justify-between border-t border-slate-200 pt-3 text-sm dark:border-slate-800">
        <p class="text-slate-500 dark:text-slate-400">
            Showing <span class="font-medium text-slate-700 dark:text-slate-300">{{ $paginator->from() }}</span>
            to <span class="font-medium text-slate-700 dark:text-slate-300">{{ $paginator->to() }}</span>
            of <span class="font-medium text-slate-700 dark:text-slate-300">{{ $paginator->total() }}</span>
        </p>

        <div class="flex items-center gap-1">
            <button type="button" wire:click="previousPage" @if ($current <= 1) disabled @endif
                class="rounded-md border border-slate-200 px-3 py-1.5 text-slate-600 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">
                Prev
            </button>

            @if ($start > 1)
                <button type="button" wire:click="gotoPage(1)"
                    class="rounded-md border border-slate-200 px-3 py-1.5 text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">1</button>
                @if ($start > 2)
                    <span class="px-1 text-slate-400">…</span>
                @endif
            @endif

            @for ($page = $start; $page <= $end; $page++)
                <button type="button" wire:click="gotoPage({{ $page }})"
                    class="rounded-md border px-3 py-1.5 {{ $page === $current
                        ? 'border-brand-600 bg-brand-600 text-white dark:border-brand-500 dark:bg-brand-500'
                        : 'border-slate-200 text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800' }}">
                    {{ $page }}
                </button>
            @endfor

            @if ($end < $last)
                @if ($end < $last - 1)
                    <span class="px-1 text-slate-400">…</span>
                @endif
                <button type="button" wire:click="gotoPage({{ $last }})"
                    class="rounded-md border border-slate-200 px-3 py-1.5 text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">{{ $last }}</button>
            @endif

            <button type="button" wire:click="nextPage" @if (! $paginator->hasMorePages()) disabled @endif
                class="rounded-md border border-slate-200 px-3 py-1.5 text-slate-600 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">
                Next
            </button>
        </div>
    </nav>
@endif
