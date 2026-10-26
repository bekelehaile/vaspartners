<x-filament-panels::page>
    @php($data = $this->getViewData())
    <div class="grid gap-6 md:grid-cols-3">
        <x-filament::section>
            <x-slot name="heading">By status</x-slot>
            <ul class="space-y-2 text-sm">
                @forelse ($data['byStatus'] as $status => $count)
                    <li class="flex justify-between gap-4">
                        <span class="font-medium">{{ str_replace('_', ' ', ucfirst($status)) }}</span>
                        <span>{{ $count }}</span>
                    </li>
                @empty
                    <li class="text-gray-500">No provisioning cases yet.</li>
                @endforelse
            </ul>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Waiting by department</x-slot>
            <ul class="space-y-2 text-sm">
                @forelse ($data['waitingByDept'] as $dept => $count)
                    <li class="flex justify-between gap-4">
                        <span class="font-medium">{{ str_replace('_', ' ', ucfirst($dept)) }}</span>
                        <span>{{ $count }}</span>
                    </li>
                @empty
                    <li class="text-gray-500">No waiting steps.</li>
                @endforelse
            </ul>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Overall time (open cases)</x-slot>
            <ul class="space-y-2 text-sm">
                <li class="flex justify-between gap-4"><span>0–3 days</span><span>{{ $data['aging']['0-3'] }}</span></li>
                <li class="flex justify-between gap-4"><span>4–7 days</span><span>{{ $data['aging']['4-7'] }}</span></li>
                <li class="flex justify-between gap-4"><span>8+ days</span><span>{{ $data['aging']['8+'] }}</span></li>
            </ul>
        </x-filament::section>
    </div>

    <x-filament::section class="mt-6">
        <x-slot name="heading">Recent open cases</x-slot>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-gray-500">
                        <th class="py-2 pr-4">PRV</th>
                        <th class="py-2 pr-4">Company</th>
                        <th class="py-2 pr-4">Service</th>
                        <th class="py-2 pr-4">Status</th>
                        <th class="py-2">Overall days</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($data['recent'] as $case)
                        <tr class="border-t border-gray-100 dark:border-gray-800">
                            <td class="py-2 pr-4 font-medium">
                                <a class="text-primary-600 hover:underline" href="{{ \App\Filament\Resources\ServiceProvisionings\ServiceProvisioningResource::getUrl('view', ['record' => $case]) }}">
                                    {{ $case->public_id }}
                                </a>
                            </td>
                            <td class="py-2 pr-4">{{ $case->company?->name }}</td>
                            <td class="py-2 pr-4">{{ $case->service?->name }}</td>
                            <td class="py-2 pr-4">{{ $case->status?->label() }}</td>
                            <td class="py-2">{{ $case->overallElapsedDays() }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="py-4 text-gray-500">No open cases.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
