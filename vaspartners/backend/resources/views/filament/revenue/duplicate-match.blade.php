@php
    /** @var array{partner_name:?string,service_id:?string,short_code:?string,amount_label:string,phone:?string,period:string,status:string,error:string} $summary */
    /** @var list<array<string, mixed>> $matches */
@endphp

<div class="space-y-5">
    <div class="rounded-xl border border-danger-200 bg-danger-50 px-4 py-3 dark:border-danger-500/30 dark:bg-danger-500/10">
        <div class="text-xs font-semibold uppercase tracking-wide text-danger-700 dark:text-danger-300">Why blocked</div>
        <p class="mt-1 text-sm text-danger-900 dark:text-danger-100">{{ $summary['error'] }}</p>
    </div>

    <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <div class="mb-3 flex items-center justify-between gap-3">
            <div>
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">This payload row</div>
                <div class="mt-0.5 text-base font-semibold text-gray-950 dark:text-white">
                    {{ $summary['partner_name'] ?: 'Unknown partner' }}
                </div>
            </div>
            <span class="inline-flex items-center rounded-md bg-danger-100 px-2 py-1 text-xs font-medium text-danger-700 dark:bg-danger-500/20 dark:text-danger-300">
                {{ $summary['status'] }}
            </span>
        </div>
        <dl class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm sm:grid-cols-3">
            <div>
                <dt class="text-xs text-gray-500 dark:text-gray-400">Period</dt>
                <dd class="font-medium text-gray-950 dark:text-white">{{ $summary['period'] }}</dd>
            </div>
            <div>
                <dt class="text-xs text-gray-500 dark:text-gray-400">Amount</dt>
                <dd class="font-medium text-gray-950 dark:text-white">{{ $summary['amount_label'] }}</dd>
            </div>
            <div>
                <dt class="text-xs text-gray-500 dark:text-gray-400">Phone</dt>
                <dd class="font-medium text-gray-950 dark:text-white">{{ $summary['phone'] ?: '—' }}</dd>
            </div>
            <div class="col-span-2 sm:col-span-2">
                <dt class="text-xs text-gray-500 dark:text-gray-400">Service ID</dt>
                <dd class="font-mono text-sm font-medium text-gray-950 dark:text-white">{{ $summary['service_id'] ?: '—' }}</dd>
            </div>
            <div>
                <dt class="text-xs text-gray-500 dark:text-gray-400">Short code</dt>
                <dd class="font-medium text-gray-950 dark:text-white">{{ $summary['short_code'] ?: '—' }}</dd>
            </div>
        </dl>
    </div>

    <div>
        <div class="mb-2 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Matching sends</h3>
            <span class="text-xs text-gray-500 dark:text-gray-400">{{ count($matches) }} found</span>
        </div>

        @if ($matches === [])
            <div class="rounded-xl border border-dashed border-gray-300 px-4 py-6 text-center text-sm text-gray-500 dark:border-gray-600 dark:text-gray-400">
                No matching prior or same-import rows were found. Rematch may clear this if the duplicate policy changed.
            </div>
        @else
            <ul class="space-y-3">
                @foreach ($matches as $match)
                    <li class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
                        <div class="flex flex-wrap items-start justify-between gap-3 border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                            <div class="min-w-0">
                                <div class="text-xs font-semibold uppercase tracking-wide text-primary-600 dark:text-primary-400">
                                    {{ $match['source'] }}
                                </div>
                                <a
                                    href="{{ $match['url'] }}"
                                    target="_blank"
                                    rel="noopener"
                                    class="mt-0.5 block truncate text-sm font-semibold text-gray-950 underline-offset-2 hover:underline dark:text-white"
                                >
                                    {{ $match['import_title'] }}
                                </a>
                                @if (! empty($match['import_public_id']))
                                    <div class="mt-0.5 font-mono text-xs text-gray-500 dark:text-gray-400">
                                        {{ $match['import_public_id'] }}
                                    </div>
                                @endif
                            </div>
                            <div class="text-right">
                                <div class="text-base font-semibold text-gray-950 dark:text-white">{{ $match['amount_label'] }}</div>
                                <div class="text-xs text-gray-500 dark:text-gray-400">{{ $match['status'] }}</div>
                            </div>
                        </div>

                        <dl class="grid grid-cols-2 gap-x-4 gap-y-3 px-4 py-3 text-sm sm:grid-cols-3">
                            <div>
                                <dt class="text-xs text-gray-500 dark:text-gray-400">Account manager</dt>
                                <dd class="font-medium text-gray-950 dark:text-white">{{ $match['am_name'] ?: '—' }}</dd>
                                @if (! empty($match['am_email']))
                                    <dd class="truncate text-xs text-gray-500 dark:text-gray-400">{{ $match['am_email'] }}</dd>
                                @endif
                            </div>
                            <div>
                                <dt class="text-xs text-gray-500 dark:text-gray-400">Sent by</dt>
                                <dd class="font-medium text-gray-950 dark:text-white">{{ $match['sent_by_name'] ?: '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-gray-500 dark:text-gray-400">When</dt>
                                <dd class="font-medium text-gray-950 dark:text-white">{{ $match['sent_at'] ?: '—' }}</dd>
                                @if (! empty($match['sent_at_relative']))
                                    <dd class="text-xs text-gray-500 dark:text-gray-400">{{ $match['sent_at_relative'] }}</dd>
                                @endif
                            </div>
                            <div>
                                <dt class="text-xs text-gray-500 dark:text-gray-400">Period</dt>
                                <dd class="font-medium text-gray-950 dark:text-white">{{ $match['period'] }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-gray-500 dark:text-gray-400">Service</dt>
                                <dd class="font-medium text-gray-950 dark:text-white">{{ $match['catalog_service'] ?: '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-gray-500 dark:text-gray-400">Phone</dt>
                                <dd class="font-medium text-gray-950 dark:text-white">{{ $match['phone'] ?: '—' }}</dd>
                            </div>
                            <div class="col-span-2 sm:col-span-2">
                                <dt class="text-xs text-gray-500 dark:text-gray-400">Partner</dt>
                                <dd class="font-medium text-gray-950 dark:text-white">{{ $match['partner_name'] ?: '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-gray-500 dark:text-gray-400">Service ID</dt>
                                <dd class="font-mono text-xs font-medium text-gray-950 dark:text-white">{{ $match['service_id'] ?: '—' }}</dd>
                            </div>
                        </dl>

                        <div class="border-t border-gray-100 px-4 py-2.5 dark:border-gray-800">
                            <a
                                href="{{ $match['url'] }}"
                                target="_blank"
                                rel="noopener"
                                class="inline-flex items-center gap-1 text-sm font-medium text-primary-600 hover:underline dark:text-primary-400"
                            >
                                Open original import
                                <span aria-hidden="true">→</span>
                            </a>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
