<x-filament-panels::page>
    @include('filament.components.kardex-tabs', ['active' => 'valued'])

    <div class="space-y-6">
        {{-- Selector de Pestañas Nativo de Filament --}}
        <x-filament::tabs>
            <x-filament::tabs.item
                :active="$activeTab === 'valuation'"
                wire:click="setActiveTab('valuation')"
                icon="heroicon-o-table-cells"
            >
                Valorización
            </x-filament::tabs.item>

            <x-filament::tabs.item
                :active="$activeTab === 'movements'"
                wire:click="setActiveTab('movements')"
                icon="heroicon-o-book-open"
            >
                Movimientos por prenda
            </x-filament::tabs.item>
        </x-filament::tabs>

        {{-- ============================================================== --}}
        {{-- PESTAÑA A: REPORTE DE VALORIZACIÓN                             --}}
        {{-- ============================================================== --}}
        @if ($activeTab === 'valuation')
            {{-- Filtros Superiores --}}
            <x-filament::section>
                {{ $this->form }}
            </x-filament::section>

            {{-- 4 Tarjetas Stats de Consolidado (Ocultas para almacenero) --}}
            @if ($this->canViewCosts())
                <div class="grid gap-4 grid-cols-1 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($this->getStats() as $stat)
                        {{ $stat }}
                    @endforeach
                </div>

                {{-- Línea de Comprobación Contable Única --}}
                @php $comp = $this->getComprobacion(); @endphp
                <x-filament::section compact>
                    <div class="flex flex-wrap items-center justify-between gap-2 text-xs">
                        <div class="flex items-center gap-2">
                            <span class="font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Comprobación:</span>
                            <span class="font-mono font-medium text-gray-800 dark:text-gray-200">
                                {{ $comp['text'] }}
                            </span>
                        </div>
                        <div>
                            <span class="font-bold {{ $comp['cuadra'] ? 'text-success-600 dark:text-success-400' : 'text-danger-600 dark:text-danger-400' }}">
                                {{ $comp['status'] }}
                            </span>
                        </div>
                    </div>
                </x-filament::section>
            @endif

            {{-- Tabla Nativa de Filament para Valorización --}}
            <div>
                {{ $this->table }}
            </div>

        {{-- ============================================================== --}}
        {{-- PESTAÑA B: FICHA KARDEX (MOVIMIENTOS POR PRENDA)                --}}
        {{-- ============================================================== --}}
        @else
            {{-- Barra de Selección de Prenda --}}
            <x-filament::section>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 items-center">
                    <div class="md:col-span-2">
                        <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">
                            Seleccionar Prenda (Modelo · Talla · Color):
                        </label>
                        <select
                            wire:model.live="selectedVariantId"
                            class="w-full text-xs rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white"
                        >
                            @foreach($this->getVariantSelectOptions() as $vId => $vLabel)
                                <option value="{{ $vId }}">{{ $vLabel }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="flex items-center gap-2 justify-end">
                        <button
                            type="button"
                            wire:click="setActiveTab('valuation')"
                            class="text-xs text-primary-600 dark:text-primary-400 hover:underline font-bold"
                        >
                            ← Volver al reporte de valorización
                        </button>
                    </div>
                </div>
            </x-filament::section>

            {{-- Infolist de Cabecera de la Prenda --}}
            @php $vData = $this->getSelectedVariantData(); @endphp
            @if ($vData)
                <x-filament::section>
                    <x-slot name="heading">
                        {{ $vData['product_name'] }}
                    </x-slot>
                    <x-slot name="description">
                        Categoría: {{ $vData['category_name'] }} · SKU: <span class="font-mono font-bold">{{ $vData['sku'] }}</span> · Almacén: {{ $vData['warehouse'] }}
                    </x-slot>

                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-xs pt-1">
                        <div>
                            <span class="text-gray-500 dark:text-gray-400 font-medium">Talla y Color:</span>
                            <div class="font-bold text-gray-900 dark:text-white mt-0.5">
                                {{ $vData['size'] }} / {{ $vData['color'] }}
                            </div>
                        </div>

                        <div>
                            <span class="text-gray-500 dark:text-gray-400 font-medium">Saldo inicial al {{ $vData['initial_date_label'] }}:</span>
                            <div class="font-bold text-gray-900 dark:text-white mt-0.5">
                                {{ number_format($vData['initial_qty']) }} unid.
                                @if($this->canViewCosts())
                                    <span class="text-gray-400 font-normal">·</span>
                                    <span class="font-mono text-gray-600 dark:text-gray-300">C.U. S/ {{ number_format($vData['initial_unit'], 2) }}</span>
                                    <span class="text-gray-400 font-normal">·</span>
                                    <span class="font-mono text-primary-600 dark:text-primary-400">Total S/ {{ number_format($vData['initial_cost'], 2) }}</span>
                                @endif
                            </div>
                        </div>

                        <div>
                            <span class="text-gray-500 dark:text-gray-400 font-medium">Stock en periodo:</span>
                            <div class="font-bold text-gray-900 dark:text-white mt-0.5 text-sm">
                                {{ number_format($vData['current_stock']) }} unid.
                            </div>
                        </div>

                        @if($this->canViewCosts())
                            <div>
                                <span class="text-gray-500 dark:text-gray-400 font-medium">Costo promedio y Valor final:</span>
                                <div class="font-bold font-mono text-gray-900 dark:text-white mt-0.5">
                                    S/ {{ number_format($vData['current_unit'], 2) }}
                                    <span class="text-gray-400 font-normal">·</span>
                                    <span class="text-primary-600 dark:text-primary-400">S/ {{ number_format($vData['current_total'], 2) }}</span>
                                </div>
                            </div>
                        @endif
                    </div>
                </x-filament::section>
            @endif

            {{-- Tabla de Movimientos con ColumnGroup --}}
            <div>
                {{ $this->table }}
            </div>
        @endif
    </div>
</x-filament-panels::page>
