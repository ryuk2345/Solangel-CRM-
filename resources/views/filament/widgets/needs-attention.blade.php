<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            <div class="flex items-center gap-x-2">
                <span>Requiere atención</span>
                @if ($items->count() > 0)
                    <x-filament::badge color="danger">
                        {{ $items->count() }}
                    </x-filament::badge>
                @endif
            </div>
        </x-slot>

        @if ($items->isEmpty())
            <div class="py-4 text-center">
                <span class="text-sm font-semibold text-success-600 dark:text-success-400">Todo en orden ✓</span>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">No hay incidencias operativas ni acciones pendientes que requieran tu atención.</p>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left border-collapse">
                    <thead class="text-xs text-gray-500 uppercase border-b border-gray-200 dark:border-white/10 dark:text-gray-400">
                        <tr>
                            <th scope="col" class="py-2.5 px-3">Qué pasa</th>
                            <th scope="col" class="py-2.5 px-3">Cantidad / Detalle</th>
                            <th scope="col" class="py-2.5 px-3 text-right">Acción</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/5">
                        @foreach ($items as $item)
                            <tr>
                                <td class="py-3 px-3 font-medium text-gray-900 dark:text-white">
                                    <div class="flex items-center gap-x-2">
                                        <x-filament::badge :color="$item->color">
                                            {{ $item->issue }}
                                        </x-filament::badge>
                                    </div>
                                </td>
                                <td class="py-3 px-3 text-gray-600 dark:text-gray-300">
                                    {{ $item->detail }}
                                </td>
                                <td class="py-3 px-3 text-right">
                                    <x-filament::button size="xs" color="gray" tag="a" :href="$item->url">
                                        Ver
                                    </x-filament::button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
