<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            <div class="flex items-center gap-x-2">
                <span>Reponer primero</span>
                @if ($items->count() > 0)
                    <x-filament::badge color="warning">
                        {{ $items->count() }} variantes urgentes
                    </x-filament::badge>
                @endif
            </div>
        </x-slot>

        @if ($reportUrl)
            <x-slot name="headerEnd">
                <x-filament::button size="xs" color="gray" tag="a" :href="$reportUrl">
                    Ver todo
                </x-filament::button>
            </x-slot>
        @endif

        @if ($items->isEmpty())
            <div class="py-4 text-center">
                <span class="text-sm font-semibold text-success-600 dark:text-success-400">Todo el inventario sobre el umbral ✓</span>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">No hay variantes que requieran reposición inmediata.</p>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left border-collapse">
                    <thead class="text-xs text-gray-500 uppercase border-b border-gray-200 dark:border-white/10 dark:text-gray-400">
                        <tr>
                            <th scope="col" class="py-2.5 px-3">Modelo</th>
                            <th scope="col" class="py-2.5 px-2 text-center">Talla</th>
                            <th scope="col" class="py-2.5 px-2">Color</th>
                            <th scope="col" class="py-2.5 px-3">Tienda / Almacén</th>
                            <th scope="col" class="py-2.5 px-3 text-right">Stock</th>
                            <th scope="col" class="py-2.5 px-3 text-right">Vendidas 30 d</th>
                            <th scope="col" class="py-2.5 px-3 text-center">Días de stock</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/5">
                        @foreach ($items as $item)
                            <tr>
                                <td class="py-2.5 px-3 font-semibold text-gray-900 dark:text-white">
                                    {{ $item->product_name }}
                                </td>
                                <td class="py-2.5 px-2 text-center">
                                    <x-filament::badge color="info">
                                        {{ $item->size }}
                                    </x-filament::badge>
                                </td>
                                <td class="py-2.5 px-2">
                                    <x-filament::badge color="gray">
                                        {{ $item->color_name }}
                                    </x-filament::badge>
                                </td>
                                <td class="py-2.5 px-3 text-gray-600 dark:text-gray-400">
                                    {{ $item->location_name }}
                                </td>
                                <td class="py-2.5 px-3 text-right font-medium text-gray-900 dark:text-white">
                                    {{ $item->stock }}
                                </td>
                                <td class="py-2.5 px-3 text-right text-gray-600 dark:text-gray-300">
                                    {{ $item->sold_30d }}
                                </td>
                                <td class="py-2.5 px-3 text-center">
                                    @if ($item->days_of_stock === null)
                                        <x-filament::badge color="gray">
                                            Sin ventas
                                        </x-filament::badge>
                                    @elseif ($item->days_of_stock < 7)
                                        <x-filament::badge color="danger">
                                            {{ $item->days_of_stock }} días
                                        </x-filament::badge>
                                    @elseif ($item->days_of_stock < 15)
                                        <x-filament::badge color="warning">
                                            {{ $item->days_of_stock }} días
                                        </x-filament::badge>
                                    @else
                                        <x-filament::badge color="gray">
                                            {{ $item->days_of_stock }} días
                                        </x-filament::badge>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
