@php
    /** @var array<string, mixed> $credentials */
    $credentials = is_array($getState()) ? $getState() : [];
    $secretKeys = ['smsc_password', 'password', 'secret', 'api_password', 'api_secret'];
@endphp

<div class="fi-in-secret-credentials grid gap-3 sm:grid-cols-2">
    @forelse ($credentials as $key => $value)
        @php
            $label = str_replace('_', ' ', ucfirst((string) $key));
            $raw = is_scalar($value) || $value === null ? (string) ($value ?? '') : json_encode($value);
            $isSecret = in_array(strtolower((string) $key), $secretKeys, true)
                || str_contains(strtolower((string) $key), 'password')
                || str_contains(strtolower((string) $key), 'secret');
        @endphp
        <div
            @if ($isSecret)
                x-data="{ show: false }"
            @endif
            class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/5"
        >
            <div class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</div>
            <div class="mt-1 flex items-start gap-2">
                @if ($isSecret)
                    <code
                        class="min-w-0 flex-1 break-all text-sm font-medium text-gray-950 dark:text-white"
                        x-text="show ? @js($raw) : '••••••••••••'"
                    ></code>
                    <button
                        type="button"
                        class="inline-flex shrink-0 items-center justify-center rounded-md p-1 text-gray-500 hover:bg-gray-200/70 hover:text-gray-800 dark:hover:bg-white/10 dark:hover:text-gray-200"
                        x-on:click="show = ! show"
                        x-bind:aria-label="show ? 'Hide value' : 'Show value'"
                        x-bind:title="show ? 'Hide' : 'Show'"
                    >
                        <x-filament::icon
                            x-show="! show"
                            icon="heroicon-m-eye"
                            class="h-5 w-5"
                        />
                        <x-filament::icon
                            x-show="show"
                            x-cloak
                            icon="heroicon-m-eye-slash"
                            class="h-5 w-5"
                        />
                    </button>
                @else
                    <code class="min-w-0 flex-1 break-all text-sm font-medium text-gray-950 dark:text-white">{{ $raw !== '' ? $raw : '—' }}</code>
                @endif
            </div>
        </div>
    @empty
        <p class="text-sm text-gray-500 dark:text-gray-400">No credentials shared.</p>
    @endforelse
</div>
