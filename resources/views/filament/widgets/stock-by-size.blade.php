<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            <div class="flex items-center gap-2">
                <x-filament::icon icon="heroicon-o-table-cells" class="w-5 h-5 text-primary-600" />
                <span>Stock por talla</span>
            </div>
        </x-slot>

        <x-slot name="headerEnd">
            <div class="flex items-center gap-3">
                {{-- Selector de categoría --}}
                <div class="flex items-center gap-1.5 text-xs">
                    <span class="text-gray-500 font-medium">Categoría:</span>
                    <select wire:model.live="category_id" class="text-xs rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 py-1 px-2.5 shadow-sm focus:ring-primary-500 focus:border-primary-500">
                        @foreach($categories as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </div>

                @if(auth()->user()?->seesAllBranches())
                    {{-- Selector de almacén --}}
                    <div class="flex items-center gap-1.5 text-xs">
                        <span class="text-gray-500 font-medium">Almacén:</span>
                        <select wire:model.live="warehouse_id" class="text-xs rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 py-1 px-2.5 shadow-sm focus:ring-primary-500 focus:border-primary-500">
                            @foreach($warehouses as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                {{-- Leyenda semáforo --}}
                <div class="hidden sm:flex items-center gap-2 text-[11px] text-gray-500 border-l border-gray-200 dark:border-gray-700 pl-3">
                    <span class="inline-flex items-center gap-1">
                        <span class="w-2.5 h-2.5 rounded-full bg-amber-400"></span> Stock bajo
                    </span>
                    <span class="inline-flex items-center gap-1 ml-1">
                        <span class="w-2.5 h-2.5 rounded-full bg-rose-500"></span> Agotado
                    </span>
                </div>
            </div>
        </x-slot>

        <div class="overflow-x-auto -mx-6 -mb-6">
            <table class="w-full text-xs text-left border-collapse">
                <thead>
                    <tr class="bg-gray-50 dark:bg-gray-800/60 text-gray-600 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
                        <th class="px-4 py-2.5 font-semibold">Modelo</th>
                        <th class="px-3 py-2.5 font-semibold">Color</th>
                        @foreach($sizes as $sizeName)
                            <th class="px-2.5 py-2.5 text-center font-bold">{{ $sizeName }}</th>
                        @endforeach
                        <th class="px-3 py-2.5 text-right font-semibold">P. Min.</th>
                        <th class="px-3 py-2.5 text-right font-semibold">P. May.</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse($rows as $row)
                        <tr class="hover:bg-gray-50/60 dark:hover:bg-gray-800/40">
                            <td class="px-4 py-2 font-medium text-gray-900 dark:text-white whitespace-nowrap">
                                {{ $row['model'] }}
                            </td>
                            <td class="px-3 py-2 text-gray-600 dark:text-gray-300 whitespace-nowrap">
                                {{ $row['color'] }}
                            </td>
                            @foreach($sizes as $sizeName)
                                @php
                                    $cell = $row['sizes'][$sizeName] ?? null;
                                @endphp
                                <td class="px-2 py-1.5 text-center">
                                    @if($cell)
                                        <span class="inline-block min-w-7 px-1.5 py-0.5 text-center rounded {{ $cell['status'] === 'agotado' ? 'bg-rose-100 text-rose-800 dark:bg-rose-950/60 dark:text-rose-300 font-bold' : ($cell['status'] === 'bajo' ? 'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300 font-bold' : 'text-gray-700 dark:text-gray-300 font-medium') }}">
                                            {{ $cell['stock'] }}
                                        </span>
                                    @else
                                        <span class="text-gray-300 dark:text-gray-600">—</span>
                                    @endif
                                </td>
                            @endforeach
                            <td class="px-3 py-2 text-right font-mono text-gray-700 dark:text-gray-300 whitespace-nowrap">
                                S/ {{ number_format($row['retail_price'], 2) }}
                            </td>
                            <td class="px-3 py-2 text-right font-mono font-semibold text-emerald-600 dark:text-emerald-400 whitespace-nowrap">
                                S/ {{ number_format($row['wholesale_price'], 2) }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ count($sizes) + 4 }}" class="px-4 py-8 text-center text-gray-400 text-sm">
                                No se encontraron modelos en esta categoría con variantes registradas.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
