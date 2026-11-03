<x-filament-panels::page>
    <form wire:submit.prevent>{{ $this->form }}</form>

    @php($report = $this->getReport())
    <p class="text-sm text-gray-500 dark:text-gray-400">Ventas netas: se descuentan las prendas devueltas en cambios y devoluciones. No incluye ventas anuladas ni el delivery.</p>

    <div class="grid gap-6 md:grid-cols-2">
        @foreach (\App\Filament\Pages\SalesReport::SECTIONS as $key => $title)
            <x-filament::section>
                <x-slot name="heading">{{ $title }}</x-slot>
                @if (auth()->user()?->can('export_reports'))
                    <x-slot name="headerEnd">
                        <x-filament::button size="xs" color="gray" icon="heroicon-o-arrow-down-tray" wire:click="export('{{ $key }}')">CSV</x-filament::button>
                    </x-slot>
                @endif

                @if ($report[$key]->isEmpty())
                    <p class="text-sm text-gray-500">Sin ventas en el periodo.</p>
                @else
                    @php($max = max(1, $report[$key]->max('units')))
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-500 dark:text-gray-400">
                                <th class="py-1 pr-2 font-medium">{{ $key === 'model' ? 'Modelo' : 'Valor' }}</th>
                                <th class="py-1 pr-2 font-medium w-1/3"></th>
                                <th class="py-1 pr-2 font-medium text-right">Prendas</th>
                                <th class="py-1 font-medium text-right">S/</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($report[$key] as $row)
                                <tr class="border-t border-gray-100 dark:border-white/5">
                                    <td class="py-1.5 pr-2 font-medium">{{ $row['label'] }}</td>
                                    <td class="py-1.5 pr-2">
                                        <div class="h-2 rounded bg-primary-500" style="width: {{ round($row['units'] * 100 / $max) }}%"></div>
                                    </td>
                                    <td class="py-1.5 pr-2 text-right tabular-nums">{{ $row['units'] }} <span class="text-xs text-gray-500">({{ $row['share'] }}%)</span></td>
                                    <td class="py-1.5 text-right tabular-nums">{{ number_format($row['amount'], 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </x-filament::section>
        @endforeach
    </div>
</x-filament-panels::page>
