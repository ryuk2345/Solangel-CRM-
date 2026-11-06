<x-filament-panels::page>
    @include('filament.components.inventory-tabs', ['active' => 'matrix'])

    <form wire:submit.prevent>
        {{ $this->form }}
    </form>

    @php($m = $this->getMatrix())

    @if (! $m['product'])
        <x-filament::section>
            <p class="text-sm text-gray-500">Elige un modelo para ver su stock por talla y color.</p>
        </x-filament::section>
    @else
        <x-filament::section>
            <x-slot name="heading">{{ $m['product']->name }}</x-slot>
            <x-slot name="description">
                Total: {{ $m['total'] }} unidades · Stock bajo cuando una talla y color tiene {{ $m['threshold'] }} o menos.
            </x-slot>

            <div class="overflow-x-auto">
                <table class="w-full text-sm border-collapse">
                    <thead>
                        <tr>
                            <th class="p-2 text-left font-semibold text-gray-600 dark:text-gray-300">Color</th>
                            @foreach ($m['sizes'] as $size)
                                <th class="p-2 text-center font-bold">{{ $size }}</th>
                            @endforeach
                            <th class="p-2 text-center font-semibold text-gray-600 dark:text-gray-300">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($m['rows'] as $row)
                            <tr class="border-t border-gray-200 dark:border-white/10">
                                <td class="p-2 whitespace-nowrap">
                                    <span class="inline-flex items-center gap-2 font-medium">
                                        <span class="inline-block w-4 h-4 rounded-full border border-gray-300" style="background: {{ $row['hex'] ?: '#e5e7eb' }}"></span>
                                        {{ $row['color'] }}
                                    </span>
                                </td>
                                @foreach ($m['sizes'] as $size)
                                    @php($q = $row['cells'][$size])
                                    <td class="p-1 text-center">
                                        @if (is_null($q))
                                            <span class="text-gray-400">—</span>
                                        @else
                                            <span @class([
                                                'block rounded-md py-2 font-semibold border',
                                                'bg-danger-50 text-danger-700 border-danger-200 dark:bg-danger-500/10 dark:text-danger-400' => $q <= 0,
                                                'bg-warning-50 text-warning-700 border-warning-200 dark:bg-warning-500/10 dark:text-warning-400' => $q > 0 && $q <= $m['threshold'],
                                                'border-gray-200 dark:border-white/10' => $q > $m['threshold'],
                                            ])>{{ $q }}</span>
                                        @endif
                                    </td>
                                @endforeach
                                <td class="p-2 text-center font-bold">{{ $row['total'] }}</td>
                            </tr>
                        @endforeach
                        <tr class="border-t-2 border-gray-300 dark:border-white/20">
                            <td class="p-2 font-semibold">Total por talla</td>
                            @foreach ($m['sizes'] as $size)
                                <td class="p-2 text-center font-bold">{{ $m['totals'][$size] }}</td>
                            @endforeach
                            <td class="p-2 text-center font-bold">{{ $m['total'] }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="flex flex-wrap gap-4 mt-4 text-xs text-gray-600 dark:text-gray-400">
                <span class="inline-flex items-center gap-1"><span class="w-3 h-3 rounded bg-danger-100 border border-danger-300"></span> Agotado</span>
                <span class="inline-flex items-center gap-1"><span class="w-3 h-3 rounded bg-warning-100 border border-warning-300"></span> Stock bajo</span>
                <span class="inline-flex items-center gap-1"><span class="w-3 h-3 rounded border border-gray-300"></span> Disponible</span>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
