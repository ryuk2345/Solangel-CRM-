<x-filament-panels::page>
    <form wire:submit.prevent>{{ $this->form }}</form>

    @php($broken = $this->getBroken())
    @php($dead = $this->getDead())
    @php($canExport = auth()->user()?->can('export_reports'))
    @php($showCost = auth()->user()?->can('view_cost_price'))

    <x-filament::section>
        <x-slot name="heading">Curva rota ({{ $broken->count() }})</x-slot>
        <x-slot name="description">Modelos y colores que todavía tienen stock pero ya se quedaron sin alguna talla. Conviene reponer esas tallas o traspasarlas.</x-slot>
        @if ($canExport)
            <x-slot name="headerEnd"><x-filament::button size="xs" color="gray" icon="heroicon-o-arrow-down-tray" wire:click="exportBroken">CSV</x-filament::button></x-slot>
        @endif
        @if ($broken->isEmpty())
            <p class="text-sm text-gray-500">No hay curvas rotas.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-gray-500 dark:text-gray-400">
                        <th class="py-1 pr-3 font-medium">Modelo</th><th class="py-1 pr-3 font-medium">Color</th><th class="py-1 pr-3 font-medium">Almacén</th>
                        <th class="py-1 pr-3 font-medium">Tallas agotadas</th><th class="py-1 pr-3 font-medium">Tallas con stock</th><th class="py-1 text-right font-medium">Unid.</th>
                    </tr></thead>
                    <tbody>
                        @foreach ($broken as $r)
                            <tr class="border-t border-gray-100 dark:border-white/5">
                                <td class="py-1.5 pr-3 font-medium">{{ $r['model'] }}</td>
                                <td class="py-1.5 pr-3">{{ $r['color'] }}</td>
                                <td class="py-1.5 pr-3">{{ $r['warehouse'] }}</td>
                                <td class="py-1.5 pr-3">@foreach ($r['missing'] as $s)<x-filament::badge color="danger" class="inline-flex mr-1">{{ $s }}</x-filament::badge>@endforeach</td>
                                <td class="py-1.5 pr-3 text-gray-600 dark:text-gray-300">{{ implode(' · ', $r['available']) }}</td>
                                <td class="py-1.5 text-right tabular-nums">{{ $r['units'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Stock sin movimiento ({{ $dead->count() }})</x-slot>
        <x-slot name="description">Prendas con stock que no se venden hace más de {{ (int) ($this->data['days'] ?? 90) }} días. Candidatas a liquidación o traspaso.</x-slot>
        @if ($canExport)
            <x-slot name="headerEnd"><x-filament::button size="xs" color="gray" icon="heroicon-o-arrow-down-tray" wire:click="exportDead">CSV</x-filament::button></x-slot>
        @endif
        @if ($dead->isEmpty())
            <p class="text-sm text-gray-500">Todo el stock tuvo ventas en ese periodo.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-gray-500 dark:text-gray-400">
                        <th class="py-1 pr-3 font-medium">Modelo</th><th class="py-1 pr-3 font-medium">Color</th><th class="py-1 pr-3 font-medium">Talla</th>
                        <th class="py-1 pr-3 font-medium">Almacén</th><th class="py-1 pr-3 text-right font-medium">Stock</th>
                        <th class="py-1 pr-3 font-medium">Última venta</th><th class="py-1 pr-3 text-right font-medium">Días</th>
                        @if ($showCost)<th class="py-1 text-right font-medium">Valor al costo</th>@endif
                    </tr></thead>
                    <tbody>
                        @foreach ($dead->take(300) as $r)
                            <tr class="border-t border-gray-100 dark:border-white/5">
                                <td class="py-1.5 pr-3 font-medium">{{ $r['model'] }}</td><td class="py-1.5 pr-3">{{ $r['color'] }}</td><td class="py-1.5 pr-3">{{ $r['size'] }}</td>
                                <td class="py-1.5 pr-3">{{ $r['warehouse'] }}</td><td class="py-1.5 pr-3 text-right tabular-nums">{{ $r['stock'] }}</td>
                                <td class="py-1.5 pr-3">{{ $r['last_sale'] ?? 'Nunca' }}</td><td class="py-1.5 pr-3 text-right tabular-nums">{{ $r['days'] }}</td>
                                @if ($showCost)<td class="py-1.5 text-right tabular-nums">S/ {{ number_format($r['cost_value'], 2) }}</td>@endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
