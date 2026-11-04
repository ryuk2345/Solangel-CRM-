<x-filament-panels::page>
    @include('filament.components.kardex-tabs', ['active' => 'valued'])

    <div class="space-y-6">
        {{-- 1. Barra de Filtros --}}
        <div class="bg-white dark:bg-gray-800 p-4 rounded-xl shadow-sm border border-gray-300 dark:border-gray-700">
            {{ $this->form }}
        </div>

        @php
            $report = $this->report;
            $canViewCosts = $this->canViewCosts();
            $consolidated = $report['consolidated'];
            $allBlocks = $this->allBlocks;
            $detailBlocks = $this->detailBlocks;

            $totalStockQty = array_sum(array_column(array_column($allBlocks, 'totals'), 'balance_qty'));
            $totalEntriesQty = array_sum(array_column(array_column($allBlocks, 'totals'), 'entries_qty'));
            $totalExitsQty = array_sum(array_column(array_column($allBlocks, 'totals'), 'exits_qty'));
        @endphp

        {{-- 2. Tarjetas KPI de Resumen Ejecutivo --}}
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3">
            {{-- Stock total unidades --}}
            <div class="bg-white dark:bg-gray-800 p-3.5 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700">
                <span class="text-[11px] font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider flex items-center gap-1">
                    <x-filament::icon icon="heroicon-m-archive-box" class="w-3.5 h-3.5 text-primary-500" />
                    Prendas en Stock
                </span>
                <div class="text-xl font-black text-gray-900 dark:text-white mt-1">
                    {{ number_format($totalStockQty) }} <span class="text-xs font-semibold text-gray-500 dark:text-gray-400">unid.</span>
                </div>
                <div class="text-[11px] text-gray-500 dark:text-gray-400 mt-0.5">
                    {{ count($allBlocks) }} prendas / variantes
                </div>
            </div>

            @if($canViewCosts)
                {{-- Valor total inventario final --}}
                <div class="bg-white dark:bg-gray-800 p-3.5 rounded-xl shadow-sm border border-sky-200 dark:border-sky-800/60">
                    <span class="text-[11px] font-bold text-sky-700 dark:text-sky-400 uppercase tracking-wider flex items-center gap-1">
                        <x-filament::icon icon="heroicon-m-banknotes" class="w-3.5 h-3.5" />
                        Valor Total Stock
                    </span>
                    <div class="text-xl font-black text-sky-600 dark:text-sky-400 mt-1 font-mono">
                        S/ {{ number_format($consolidated['final_cost'], 2) }}
                    </div>
                    <div class="text-[11px] text-gray-500 dark:text-gray-400 mt-0.5">
                        Al cierre del periodo
                    </div>
                </div>

                {{-- Compras / Entradas del periodo --}}
                <div class="bg-white dark:bg-gray-800 p-3.5 rounded-xl shadow-sm border border-emerald-200 dark:border-emerald-800/60">
                    <span class="text-[11px] font-bold text-emerald-700 dark:text-emerald-400 uppercase tracking-wider flex items-center gap-1">
                        <x-filament::icon icon="heroicon-m-arrow-down-tray" class="w-3.5 h-3.5" />
                        (+) Entradas Mes
                    </span>
                    <div class="text-xl font-black text-emerald-600 dark:text-emerald-400 mt-1 font-mono">
                        S/ {{ number_format($consolidated['entries_cost'], 2) }}
                    </div>
                    <div class="text-[11px] text-emerald-700 dark:text-emerald-400 mt-0.5 font-medium">
                        +{{ number_format($totalEntriesQty) }} unidades
                    </div>
                </div>

                {{-- Costo de ventas / Salidas del periodo --}}
                <div class="bg-white dark:bg-gray-800 p-3.5 rounded-xl shadow-sm border border-indigo-200 dark:border-indigo-800/60">
                    <span class="text-[11px] font-bold text-indigo-700 dark:text-indigo-400 uppercase tracking-wider flex items-center gap-1">
                        <x-filament::icon icon="heroicon-m-arrow-up-tray" class="w-3.5 h-3.5" />
                        (=) Costo de Ventas
                    </span>
                    <div class="text-xl font-black text-indigo-600 dark:text-indigo-400 mt-1 font-mono">
                        S/ {{ number_format($consolidated['cost_of_sales'], 2) }}
                    </div>
                    <div class="text-[11px] text-indigo-700 dark:text-indigo-400 mt-0.5 font-medium">
                        -{{ number_format($totalExitsQty) }} unidades
                    </div>
                </div>

                {{-- Comprobación contable --}}
                <div class="bg-white dark:bg-gray-800 p-3.5 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700">
                    <span class="text-[11px] font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider flex items-center gap-1">
                        <x-filament::icon icon="heroicon-m-check-badge" class="w-3.5 h-3.5 {{ abs($consolidated['difference']) < 0.01 ? 'text-emerald-500' : 'text-rose-500' }}" />
                        Comprobación
                    </span>
                    <div class="text-xl font-black {{ abs($consolidated['difference']) < 0.01 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400' }} mt-1 font-mono">
                        S/ {{ number_format($consolidated['difference'], 2) }}
                    </div>
                    <div class="text-[11px] font-semibold {{ abs($consolidated['difference']) < 0.01 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400' }} mt-0.5">
                        {{ abs($consolidated['difference']) < 0.01 ? '✓ Saldo exacto' : '⚠ Descuadre' }}
                    </div>
                </div>
            @endif
        </div>

        {{-- 3. Selector de Modo: Resumen de Inventario vs Ficha Kardex --}}
        <div class="flex flex-wrap items-center justify-between gap-3 bg-slate-100 dark:bg-gray-900 p-2 rounded-xl border border-gray-300 dark:border-gray-700">
            <div class="inline-flex rounded-lg p-1 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 shadow-xs">
                <button
                    type="button"
                    wire:click="showSummary"
                    class="px-4 py-1.5 rounded-md text-xs font-bold transition flex items-center gap-1.5 {{ $viewMode === 'summary' ? 'bg-primary-600 text-white shadow' : 'text-gray-700 dark:text-gray-300 hover:text-gray-900 dark:hover:text-white' }}"
                >
                    <x-filament::icon icon="heroicon-m-table-cells" class="w-4 h-4" />
                    <span>Resumen de Existencias Valorizadas</span>
                    <span class="ml-1 text-[10px] px-1.5 py-0.2 rounded-full {{ $viewMode === 'summary' ? 'bg-white/20 text-white' : 'bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300' }}">
                        {{ count($allBlocks) }}
                    </span>
                </button>

                <button
                    type="button"
                    wire:click="showAllDetails"
                    class="px-4 py-1.5 rounded-md text-xs font-bold transition flex items-center gap-1.5 {{ $viewMode === 'detail' ? 'bg-primary-600 text-white shadow' : 'text-gray-700 dark:text-gray-300 hover:text-gray-900 dark:hover:text-white' }}"
                >
                    <x-filament::icon icon="heroicon-m-document-text" class="w-4 h-4" />
                    <span>Ficha Kardex (Movimientos Detallados)</span>
                </button>
            </div>

            @if($viewMode === 'detail' && $selectedSku)
                <div class="flex items-center gap-2">
                    <span class="text-xs text-gray-500 dark:text-gray-400 font-medium">Viendo ficha de:</span>
                    <span class="text-xs font-mono font-bold px-2 py-0.5 bg-primary-50 dark:bg-primary-950/70 text-primary-700 dark:text-primary-300 rounded border border-primary-300 dark:border-primary-800">
                        {{ $selectedSku }}
                    </span>
                    <button
                        type="button"
                        wire:click="showSummary"
                        class="text-xs text-primary-600 dark:text-primary-400 hover:underline font-bold ml-1 flex items-center gap-0.5"
                    >
                        ← Volver al resumen
                    </button>
                </div>
            @endif
        </div>

        {{-- =============================================================== --}}
        {{-- MODO 1: RESUMEN DE EXISTENCIAS VALORIZADAS (TABLA CONSOLIDADA) --}}
        {{-- =============================================================== --}}
        @if($viewMode === 'summary')
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-300 dark:border-gray-700 overflow-hidden">
                <div class="px-4 py-3 bg-slate-50 dark:bg-gray-900/60 border-b border-gray-300 dark:border-gray-700 flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h3 class="text-sm font-black text-gray-900 dark:text-white uppercase tracking-wider flex items-center gap-1.5">
                            <x-filament::icon icon="heroicon-o-queue-list" class="w-4 h-4 text-primary-600" />
                            Inventario de Prendas Valorizado · Periodo {{ $report['period_label'] }}
                        </h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                            Haz clic en <strong>"Ver Kardex"</strong> en cualquier prenda para auditar todas sus entradas, compras, ventas y saldos con fecha y documento.
                        </p>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-xs text-left border-collapse border border-gray-200 dark:border-gray-700">
                        <thead>
                            <tr class="bg-slate-100 dark:bg-gray-900 text-gray-800 dark:text-gray-200 border-b border-gray-300 dark:border-gray-700 text-[11px] font-black uppercase tracking-wider">
                                <th class="px-3 py-2.5 border border-gray-200 dark:border-gray-700">SKU</th>
                                <th class="px-3 py-2.5 border border-gray-200 dark:border-gray-700">Prenda / Modelo</th>
                                <th class="px-2 py-2.5 text-center border border-gray-200 dark:border-gray-700">Talla</th>
                                <th class="px-2 py-2.5 text-center border border-gray-200 dark:border-gray-700">Color</th>
                                <th class="px-3 py-2.5 border border-gray-200 dark:border-gray-700">Almacén</th>
                                <th class="px-2 py-2.5 text-right border border-gray-200 dark:border-gray-700 bg-slate-50 dark:bg-gray-800/40">Inv. Inicial</th>
                                <th class="px-2 py-2.5 text-right border border-gray-200 dark:border-gray-700 text-emerald-700 dark:text-emerald-400 bg-emerald-50/50 dark:bg-emerald-950/20">(+) Entradas</th>
                                <th class="px-2 py-2.5 text-right border border-gray-200 dark:border-gray-700 text-rose-700 dark:text-rose-400 bg-rose-50/50 dark:bg-rose-950/20">(−) Salidas</th>
                                <th class="px-2 py-2.5 text-right border border-gray-200 dark:border-gray-700 bg-primary-50/40 dark:bg-primary-950/30 text-primary-900 dark:text-primary-200 font-black">Stock Actual</th>
                                @if($canViewCosts)
                                    <th class="px-3 py-2.5 text-right border border-gray-200 dark:border-gray-700">Costo Prom.</th>
                                    <th class="px-3 py-2.5 text-right border border-gray-200 dark:border-gray-700 bg-sky-50/50 dark:bg-sky-950/30 text-sky-900 dark:text-sky-200 font-black">Total Valorizado</th>
                                @endif
                                <th class="px-3 py-2.5 text-center border border-gray-200 dark:border-gray-700 w-28">Acción</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            @forelse($allBlocks as $block)
                                @php
                                    $finalQty = (int) $block['totals']['balance_qty'];
                                    $finalTotalCost = (float) $block['totals']['balance_total'];
                                    $finalUnitCost = (float) $block['totals']['balance_unit'];
                                    $initialQty = (int) $block['initial']['qty'];
                                    $entriesQty = (int) $block['totals']['entries_qty'];
                                    $exitsQty = (int) $block['totals']['exits_qty'];
                                @endphp
                                <tr class="hover:bg-primary-50/30 dark:hover:bg-primary-950/20 transition-colors">
                                    {{-- SKU --}}
                                    <td class="px-3 py-2 border border-gray-200 dark:border-gray-700 font-mono font-bold text-primary-700 dark:text-primary-400 whitespace-nowrap">
                                        {{ $block['sku'] }}
                                    </td>

                                    {{-- Prenda --}}
                                    <td class="px-3 py-2 border border-gray-200 dark:border-gray-700 font-medium text-gray-900 dark:text-white">
                                        <div class="font-bold">{{ $block['product_name'] }}</div>
                                        <div class="text-[10px] text-gray-500 dark:text-gray-400">{{ $block['category_name'] }}</div>
                                    </td>

                                    {{-- Talla --}}
                                    <td class="px-2 py-2 text-center border border-gray-200 dark:border-gray-700">
                                        <span class="px-2 py-0.5 rounded bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-gray-200 font-bold text-[11px]">
                                            {{ $block['size_name'] }}
                                        </span>
                                    </td>

                                    {{-- Color --}}
                                    <td class="px-2 py-2 text-center border border-gray-200 dark:border-gray-700">
                                        <span class="px-2 py-0.5 rounded bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-gray-200 font-semibold text-[11px]">
                                            {{ $block['color_name'] }}
                                        </span>
                                    </td>

                                    {{-- Almacén --}}
                                    <td class="px-3 py-2 border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300 font-medium">
                                        {{ $block['warehouse_name'] }}
                                    </td>

                                    {{-- Inv Inicial --}}
                                    <td class="px-2 py-2 text-right border border-gray-200 dark:border-gray-700 font-mono text-gray-700 dark:text-gray-300">
                                        {{ $initialQty }}
                                    </td>

                                    {{-- Entradas --}}
                                    <td class="px-2 py-2 text-right border border-gray-200 dark:border-gray-700 font-mono font-bold text-emerald-700 dark:text-emerald-400 bg-emerald-50/30 dark:bg-emerald-950/10">
                                        {{ $entriesQty > 0 ? "+{$entriesQty}" : '0' }}
                                    </td>

                                    {{-- Salidas --}}
                                    <td class="px-2 py-2 text-right border border-gray-200 dark:border-gray-700 font-mono font-bold text-rose-700 dark:text-rose-400 bg-rose-50/30 dark:bg-rose-950/10">
                                        {{ $exitsQty > 0 ? "−{$exitsQty}" : '0' }}
                                    </td>

                                    {{-- Stock Actual --}}
                                    <td class="px-2 py-2 text-right border border-gray-200 dark:border-gray-700 font-black text-sm bg-primary-50/40 dark:bg-primary-950/30 {{ $finalQty > 0 ? 'text-gray-900 dark:text-white' : 'text-gray-400' }}">
                                        {{ $finalQty }}
                                    </td>

                                    @if($canViewCosts)
                                        {{-- Costo Unitario Promedio --}}
                                        <td class="px-3 py-2 text-right border border-gray-200 dark:border-gray-700 font-mono text-gray-800 dark:text-gray-200">
                                            S/ {{ number_format($finalUnitCost, 4) }}
                                        </td>

                                        {{-- Total Valorizado en Soles --}}
                                        <td class="px-3 py-2 text-right border border-gray-200 dark:border-gray-700 font-mono font-black text-sky-700 dark:text-sky-300 bg-sky-50/30 dark:bg-sky-950/20">
                                            S/ {{ number_format($finalTotalCost, 2) }}
                                        </td>
                                    @endif

                                    {{-- Acción: Ver Kardex --}}
                                    <td class="px-2 py-2 text-center border border-gray-200 dark:border-gray-700 whitespace-nowrap">
                                        <button
                                            type="button"
                                            wire:click="showKardex('{{ $block['sku'] }}', '{{ $block['warehouse_name'] }}')"
                                            class="inline-flex items-center gap-1 px-2.5 py-1 rounded-md text-xs font-bold bg-primary-50 dark:bg-primary-950/70 text-primary-700 dark:text-primary-300 border border-primary-300 dark:border-primary-800 hover:bg-primary-600 hover:text-white transition"
                                        >
                                            <x-filament::icon icon="heroicon-m-book-open" class="w-3.5 h-3.5" />
                                            <span>Ver Kardex</span>
                                        </button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="{{ $canViewCosts ? 12 : 10 }}" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">
                                        No se encontraron existencias para los filtros seleccionados en este periodo.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                        @if(count($allBlocks) > 0)
                            <tfoot>
                                <tr class="bg-slate-200/90 dark:bg-gray-900 font-black text-gray-900 dark:text-white border-t-2 border-gray-400 dark:border-gray-600 text-xs">
                                    <td colspan="5" class="px-3 py-2.5 uppercase tracking-wider text-right">
                                        TOTALES CONSOLIDADOS ({{ count($allBlocks) }} prendas):
                                    </td>
                                    <td class="px-2 py-2.5 text-right font-mono">
                                        {{ number_format(array_sum(array_column(array_column($allBlocks, 'initial'), 'qty'))) }}
                                    </td>
                                    <td class="px-2 py-2.5 text-right font-mono text-emerald-800 dark:text-emerald-300">
                                        +{{ number_format($totalEntriesQty) }}
                                    </td>
                                    <td class="px-2 py-2.5 text-right font-mono text-rose-800 dark:text-rose-300">
                                        −{{ number_format($totalExitsQty) }}
                                    </td>
                                    <td class="px-2 py-2.5 text-right font-mono text-sm font-black text-primary-800 dark:text-primary-300">
                                        {{ number_format($totalStockQty) }}
                                    </td>
                                    @if($canViewCosts)
                                        <td class="px-3 py-2.5 text-right text-gray-500 dark:text-gray-400">—</td>
                                        <td class="px-3 py-2.5 text-right font-mono text-sm font-black text-sky-800 dark:text-sky-300">
                                            S/ {{ number_format($consolidated['final_cost'], 2) }}
                                        </td>
                                    @endif
                                    <td></td>
                                </tr>
                            </tfoot>
                        @endif
                    </table>
                </div>
            </div>
        @endif

        {{-- =============================================================== --}}
        {{-- MODO 2: FICHA KARDEX DETALLADA POR PRENDA (MOVIMIENTOS)         --}}
        {{-- =============================================================== --}}
        @if($viewMode === 'detail')
            <div class="space-y-6">
                {{-- Selector de prenda rápido en modo detalle --}}
                <div class="bg-white dark:bg-gray-800 p-3.5 rounded-xl border border-gray-300 dark:border-gray-700 flex flex-wrap items-center justify-between gap-3 shadow-xs">
                    <div class="flex items-center gap-3">
                        <button
                            type="button"
                            wire:click="showSummary"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-gray-200 hover:bg-gray-200 dark:hover:bg-gray-600 transition"
                        >
                            <x-filament::icon icon="heroicon-m-arrow-left" class="w-4 h-4" />
                            <span>Volver al Resumen</span>
                        </button>

                        <div class="flex items-center gap-2">
                            <label class="text-xs font-bold text-gray-700 dark:text-gray-300">Seleccionar Prenda:</label>
                            <select
                                wire:change="selectSku($event.target.value)"
                                class="text-xs rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white py-1 px-2.5 font-medium"
                            >
                                <option value="" {{ empty($selectedSku) ? 'selected' : '' }}>-- Ver todas las prendas ({{ count($allBlocks) }}) --</option>
                                @foreach($allBlocks as $b)
                                    <option value="{{ $b['sku'] }}" {{ $selectedSku === $b['sku'] ? 'selected' : '' }}>
                                        {{ $b['sku'] }} - {{ $b['product_name'] }} ({{ $b['size_name'] }} / {{ $b['color_name'] }}) - Stock: {{ $b['totals']['balance_qty'] }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    @if(!empty($selectedSku))
                        <button
                            type="button"
                            wire:click="showAllDetails"
                            class="text-xs text-primary-600 dark:text-primary-400 hover:underline font-bold"
                        >
                            Ver todas las fichas a la vez
                        </button>
                    @endif
                </div>

                {{-- Tarjetas / Bloques de Kardex por Prenda --}}
                @forelse($detailBlocks as $block)
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow border border-gray-300 dark:border-gray-700 overflow-hidden">
                        {{-- Cabecera con datos claros de la prenda --}}
                        <div class="bg-slate-100 dark:bg-gray-900 px-4 py-3 border-b border-gray-300 dark:border-gray-700 flex flex-wrap justify-between items-center gap-3">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-mono font-bold text-primary-700 dark:text-primary-400 text-sm bg-primary-50 dark:bg-primary-950/80 px-2.5 py-1 rounded-md border border-primary-200 dark:border-primary-800">
                                    {{ $block['sku'] }}
                                </span>
                                <h3 class="font-black text-gray-900 dark:text-white text-base">
                                    {{ $block['product_name'] }}
                                </h3>
                                <span class="text-xs bg-slate-200 dark:bg-slate-700 text-slate-800 dark:text-slate-200 font-bold px-2 py-0.5 rounded">
                                    Talla: {{ $block['size_name'] }}
                                </span>
                                <span class="text-xs bg-slate-200 dark:bg-slate-700 text-slate-800 dark:text-slate-200 font-bold px-2 py-0.5 rounded">
                                    Color: {{ $block['color_name'] }}
                                </span>
                                <span class="text-xs bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300 px-2 py-0.5 rounded">
                                    {{ $block['category_name'] }}
                                </span>
                            </div>

                            <div class="flex items-center gap-3">
                                <div class="text-xs text-gray-600 dark:text-gray-300 flex items-center gap-1 font-semibold">
                                    <x-filament::icon icon="heroicon-o-building-storefront" class="w-4 h-4 text-gray-500 dark:text-gray-400" />
                                    <span>{{ $block['warehouse_name'] }}</span>
                                </div>

                                {{-- Pills de Stock y Costo Promedio --}}
                                <div class="flex items-center gap-1.5">
                                    <span class="bg-primary-50 dark:bg-primary-950/60 text-primary-800 dark:text-primary-300 text-xs px-2.5 py-1 rounded-lg border border-primary-200 dark:border-primary-800 font-black">
                                        Stock: {{ $block['totals']['balance_qty'] }} unid.
                                    </span>
                                    @if($canViewCosts)
                                        <span class="bg-sky-50 dark:bg-sky-950/60 text-sky-800 dark:text-sky-300 text-xs px-2.5 py-1 rounded-lg border border-sky-200 dark:border-sky-800 font-mono font-bold">
                                            Valor: S/ {{ number_format($block['totals']['balance_total'], 2) }}
                                        </span>
                                    @endif
                                </div>
                            </div>
                        </div>

                        {{-- Tabla estilo Kardex Valorizado Profesional --}}
                        <div class="overflow-x-auto">
                            <table class="w-full text-xs text-left border-collapse border border-gray-300 dark:border-gray-600">
                                <thead>
                                    {{-- Fila 1 de encabezados principales --}}
                                    <tr class="bg-slate-100 dark:bg-gray-900 text-gray-900 dark:text-gray-100 text-[11px] font-black uppercase">
                                        <th rowspan="2" class="px-2 py-2 text-center border border-gray-300 dark:border-gray-600 w-10">#</th>
                                        <th rowspan="2" class="px-2 py-2 text-center border border-gray-300 dark:border-gray-600 w-20">Fecha</th>
                                        <th rowspan="2" class="px-2 py-2 border border-gray-300 dark:border-gray-600 w-24">Guía</th>
                                        <th rowspan="2" class="px-2 py-2 border border-gray-300 dark:border-gray-600 w-28">Comprobante</th>
                                        <th rowspan="2" class="px-3 py-2 border border-gray-300 dark:border-gray-600 min-w-[160px]">Concepto / Operación</th>
                                        <th colspan="3" class="px-2 py-1.5 text-center border border-gray-300 dark:border-gray-600 bg-emerald-100 dark:bg-emerald-950/70 text-emerald-900 dark:text-emerald-200 tracking-wider">
                                            ENTRADAS (COMPRAS)
                                        </th>
                                        <th colspan="3" class="px-2 py-1.5 text-center border border-gray-300 dark:border-gray-600 bg-rose-100 dark:bg-rose-950/70 text-rose-900 dark:text-rose-200 tracking-wider">
                                            SALIDAS (VENTAS)
                                        </th>
                                        <th colspan="3" class="px-2 py-1.5 text-center border border-gray-300 dark:border-gray-600 bg-sky-100 dark:bg-sky-950/70 text-sky-900 dark:text-sky-200 tracking-wider">
                                            SALDOS FINALES
                                        </th>
                                    </tr>
                                    {{-- Fila 2 de sub-encabezados --}}
                                    <tr class="bg-slate-50 dark:bg-gray-800 text-gray-700 dark:text-gray-300 font-bold text-[10px]">
                                        <th class="px-2 py-1 text-right w-12 border border-gray-300 dark:border-gray-600 bg-emerald-50/50 dark:bg-emerald-950/30">Cant.</th>
                                        <th class="px-2 py-1 text-right w-16 border border-gray-300 dark:border-gray-600 bg-emerald-50/50 dark:bg-emerald-950/30">P. Unit.</th>
                                        <th class="px-2 py-1 text-right w-20 border border-gray-300 dark:border-gray-600 bg-emerald-50/50 dark:bg-emerald-950/30">Subtotal</th>

                                        <th class="px-2 py-1 text-right w-12 border border-gray-300 dark:border-gray-600 bg-rose-50/50 dark:bg-rose-950/30">Cant.</th>
                                        <th class="px-2 py-1 text-right w-16 border border-gray-300 dark:border-gray-600 bg-rose-50/50 dark:bg-rose-950/30">P. Unit.</th>
                                        <th class="px-2 py-1 text-right w-20 border border-gray-300 dark:border-gray-600 bg-rose-50/50 dark:bg-rose-950/30">Subtotal</th>

                                        <th class="px-2 py-1 text-right w-12 border border-gray-300 dark:border-gray-600 bg-sky-50/50 dark:bg-sky-950/30 font-black text-gray-900 dark:text-white">Cant.</th>
                                        <th class="px-2 py-1 text-right w-16 border border-gray-300 dark:border-gray-600 bg-sky-50/50 dark:bg-sky-950/30">Costo Prom.</th>
                                        <th class="px-2 py-1 text-right w-20 border border-gray-300 dark:border-gray-600 bg-sky-50/50 dark:bg-sky-950/30 font-black text-gray-900 dark:text-white">Total S/</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {{-- Saldo Inicial --}}
                                    <tr class="bg-slate-100/80 dark:bg-gray-900/60 font-bold text-gray-900 dark:text-gray-100">
                                        <td class="px-2 py-2 text-center border border-gray-300 dark:border-gray-600 text-gray-400">—</td>
                                        <td class="px-2 py-2 text-center border border-gray-300 dark:border-gray-600 text-gray-400">—</td>
                                        <td class="px-2 py-2 border border-gray-300 dark:border-gray-600 text-gray-400">----</td>
                                        <td class="px-2 py-2 border border-gray-300 dark:border-gray-600 text-gray-400">----</td>
                                        <td class="px-3 py-2 border border-gray-300 dark:border-gray-600 font-bold text-gray-900 dark:text-white">
                                            {{ $block['initial']['label'] }}
                                        </td>
                                        <td class="border border-gray-300 dark:border-gray-600 bg-slate-50/30 dark:bg-transparent"></td>
                                        <td class="border border-gray-300 dark:border-gray-600 bg-slate-50/30 dark:bg-transparent"></td>
                                        <td class="border border-gray-300 dark:border-gray-600 bg-slate-50/30 dark:bg-transparent"></td>
                                        <td class="border border-gray-300 dark:border-gray-600 bg-slate-50/30 dark:bg-transparent"></td>
                                        <td class="border border-gray-300 dark:border-gray-600 bg-slate-50/30 dark:bg-transparent"></td>
                                        <td class="border border-gray-300 dark:border-gray-600 bg-slate-50/30 dark:bg-transparent"></td>
                                        <td class="px-2 py-2 text-right border border-gray-300 dark:border-gray-600 font-black text-gray-900 dark:text-white">
                                            {{ $block['initial']['qty'] }}
                                        </td>
                                        <td class="px-2 py-2 text-right border border-gray-300 dark:border-gray-600 font-mono">
                                            {{ $canViewCosts ? number_format($block['initial']['unit_cost'], 4) : '—' }}
                                        </td>
                                        <td class="px-2 py-2 text-right border border-gray-300 dark:border-gray-600 font-black text-gray-900 dark:text-white">
                                            {{ $canViewCosts ? number_format($block['initial']['total_cost'], 2) : '—' }}
                                        </td>
                                    </tr>

                                    {{-- Filas de movimientos --}}
                                    @forelse($block['rows'] as $r)
                                        <tr class="hover:bg-slate-50 dark:hover:bg-gray-700/50 text-gray-900 dark:text-gray-100">
                                            <td class="px-2 py-1.5 text-center border border-gray-300 dark:border-gray-600 font-medium text-gray-500 dark:text-gray-400">{{ $r['item'] }}</td>
                                            <td class="px-2 py-1.5 text-center border border-gray-300 dark:border-gray-600 text-gray-800 dark:text-gray-200">{{ $r['date'] }}</td>
                                            <td class="px-2 py-1.5 border border-gray-300 dark:border-gray-600 font-mono text-[11px] text-gray-600 dark:text-gray-300">{{ $r['guide'] }}</td>
                                            <td class="px-2 py-1.5 border border-gray-300 dark:border-gray-600 font-mono text-[11px] font-bold text-gray-900 dark:text-white">{{ $r['doc'] }}</td>
                                            <td class="px-3 py-1.5 border border-gray-300 dark:border-gray-600 text-gray-900 dark:text-gray-100 font-medium">{{ $r['detail'] }}</td>

                                            {{-- Entradas --}}
                                            <td class="px-2 py-1.5 text-right border border-gray-300 dark:border-gray-600 font-bold {{ ($r['entry_qty'] ?? 0) < 0 ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-700 dark:text-emerald-300' }}">
                                                {{ $r['entry_qty'] !== null ? $r['entry_qty'] : '' }}
                                            </td>
                                            <td class="px-2 py-1.5 text-right border border-gray-300 dark:border-gray-600 font-mono text-gray-700 dark:text-gray-300">
                                                {{ $r['entry_unit'] !== null ? ($canViewCosts ? number_format($r['entry_unit'], 4) : '—') : '' }}
                                            </td>
                                            <td class="px-2 py-1.5 text-right border border-gray-300 dark:border-gray-600 font-mono font-semibold {{ ($r['entry_total'] ?? 0) < 0 ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-700 dark:text-emerald-300' }}">
                                                {{ $r['entry_total'] !== null ? ($canViewCosts ? number_format($r['entry_total'], 2) : '—') : '' }}
                                            </td>

                                            {{-- Salidas --}}
                                            <td class="px-2 py-1.5 text-right border border-gray-300 dark:border-gray-600 font-bold {{ ($r['exit_qty'] ?? 0) < 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-700 dark:text-rose-300' }}">
                                                {{ $r['exit_qty'] !== null ? $r['exit_qty'] : '' }}
                                            </td>
                                            <td class="px-2 py-1.5 text-right border border-gray-300 dark:border-gray-600 font-mono text-gray-700 dark:text-gray-300">
                                                {{ $r['exit_unit'] !== null ? ($canViewCosts ? number_format($r['exit_unit'], 4) : '—') : '' }}
                                            </td>
                                            <td class="px-2 py-1.5 text-right border border-gray-300 dark:border-gray-600 font-mono font-semibold {{ ($r['exit_total'] ?? 0) < 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-700 dark:text-rose-300' }}">
                                                {{ $r['exit_total'] !== null ? ($canViewCosts ? number_format($r['exit_total'], 2) : '—') : '' }}
                                            </td>

                                            {{-- Saldos --}}
                                            <td class="px-2 py-1.5 text-right border border-gray-300 dark:border-gray-600 font-black text-gray-900 dark:text-white bg-slate-50/40 dark:bg-gray-800/40">
                                                {{ $r['balance_qty'] }}
                                            </td>
                                            <td class="px-2 py-1.5 text-right border border-gray-300 dark:border-gray-600 font-mono text-gray-700 dark:text-gray-300 bg-slate-50/40 dark:bg-gray-800/40">
                                                {{ $canViewCosts ? number_format($r['balance_unit'], 4) : '—' }}
                                            </td>
                                            <td class="px-2 py-1.5 text-right border border-gray-300 dark:border-gray-600 font-mono font-black text-gray-900 dark:text-white bg-slate-50/40 dark:bg-gray-800/40">
                                                {{ $canViewCosts ? number_format($r['balance_total'], 2) : '—' }}
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="14" class="px-3 py-3 text-center text-gray-500 dark:text-gray-400 italic">
                                                No hubo nuevos movimientos en este periodo (se mantiene el saldo inicial).
                                            </td>
                                        </tr>
                                    @endforelse

                                    {{-- Totales del bloque --}}
                                    <tr class="bg-slate-200/90 dark:bg-gray-900 font-black text-gray-900 dark:text-white border-t-2 border-b-2 border-gray-400 dark:border-gray-500">
                                        <td colspan="4" class="border border-gray-300 dark:border-gray-600"></td>
                                        <td class="px-3 py-2 border border-gray-300 dark:border-gray-600 uppercase tracking-wider text-xs font-black">
                                            TOTALES DEL MES:
                                        </td>
                                        <td class="px-2 py-2 text-right border border-gray-300 dark:border-gray-600 text-emerald-800 dark:text-emerald-300">
                                            {{ $block['totals']['entries_qty'] }}
                                        </td>
                                        <td class="border border-gray-300 dark:border-gray-600"></td>
                                        <td class="px-2 py-2 text-right border border-gray-300 dark:border-gray-600 text-emerald-800 dark:text-emerald-300 font-mono font-black">
                                            {{ $canViewCosts ? number_format($block['totals']['entries_cost'], 2) : '—' }}
                                        </td>
                                        <td class="px-2 py-2 text-right border border-gray-300 dark:border-gray-600 text-rose-800 dark:text-rose-300">
                                            {{ $block['totals']['exits_qty'] }}
                                        </td>
                                        <td class="border border-gray-300 dark:border-gray-600"></td>
                                        <td class="px-2 py-2 text-right border border-gray-300 dark:border-gray-600 text-rose-800 dark:text-rose-300 font-mono font-black">
                                            {{ $canViewCosts ? number_format($block['totals']['exits_cost'], 2) : '—' }}
                                        </td>
                                        <td class="px-2 py-2 text-right border border-gray-300 dark:border-gray-600 font-black">
                                            {{ $block['totals']['balance_qty'] }}
                                        </td>
                                        <td class="border border-gray-300 dark:border-gray-600"></td>
                                        <td class="px-2 py-2 text-right border border-gray-300 dark:border-gray-600 font-mono font-black text-gray-900 dark:text-white">
                                            {{ $canViewCosts ? number_format($block['totals']['balance_total'], 2) : '—' }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        {{-- Cuadro de comprobación contable al pie de la prenda --}}
                        @if($canViewCosts)
                            <div class="bg-slate-100 dark:bg-gray-900/90 p-3 border-t border-gray-300 dark:border-gray-600 flex flex-wrap items-center justify-end gap-2 text-xs">
                                <span class="bg-white dark:bg-gray-800 text-gray-800 dark:text-gray-200 px-3 py-1.5 rounded-lg border border-gray-300 dark:border-gray-700 font-semibold shadow-xs">
                                    INV. INICIAL: <strong class="text-gray-900 dark:text-white font-mono ml-1">S/ {{ number_format($block['summary']['initial_cost'], 2) }}</strong>
                                </span>

                                <span class="bg-emerald-50 dark:bg-emerald-950/70 text-emerald-800 dark:text-emerald-300 px-3 py-1.5 rounded-lg border border-emerald-300 dark:border-emerald-800 font-semibold shadow-xs">
                                    (+) COMPRAS: <strong class="text-emerald-900 dark:text-emerald-200 font-mono ml-1">S/ {{ number_format($block['summary']['entries_cost'], 2) }}</strong>
                                </span>

                                <span class="bg-amber-50 dark:bg-amber-950/70 text-amber-800 dark:text-amber-300 px-3 py-1.5 rounded-lg border border-amber-300 dark:border-amber-800 font-semibold shadow-xs">
                                    (−) INV FINAL: <strong class="text-amber-900 dark:text-amber-200 font-mono ml-1">S/ {{ number_format(-1 * $block['summary']['final_cost'], 2) }}</strong>
                                </span>

                                <span class="bg-indigo-50 dark:bg-indigo-950/70 text-indigo-800 dark:text-indigo-300 px-3 py-1.5 rounded-lg border border-indigo-300 dark:border-indigo-800 font-bold shadow-xs">
                                    (=) COSTO DE VENTAS: <strong class="text-indigo-900 dark:text-indigo-200 font-mono ml-1">S/ {{ number_format($block['summary']['cost_of_sales'], 2) }}</strong>
                                </span>

                                <span class="px-3 py-1.5 rounded-lg border font-bold shadow-xs {{ abs($block['summary']['difference']) < 0.01 ? 'bg-emerald-100 dark:bg-emerald-900/60 text-emerald-900 dark:text-emerald-200 border-emerald-400 dark:border-emerald-700' : 'bg-rose-100 dark:bg-rose-900/60 text-rose-900 dark:text-rose-200 border-rose-400 dark:border-rose-700' }}">
                                    DIFERENCIA: <strong class="font-mono ml-1">S/ {{ number_format($block['summary']['difference'], 2) }}</strong>
                                </span>
                            </div>
                        @endif
                    </div>
                @empty
                    <div class="bg-white dark:bg-gray-800 p-8 rounded-xl text-center border border-gray-300 dark:border-gray-700">
                        <x-filament::icon icon="heroicon-o-document-magnifying-glass" class="w-12 h-12 mx-auto text-gray-400 mb-2" />
                        <p class="text-sm font-medium text-gray-600 dark:text-gray-300">
                            No se encontraron movimientos para la prenda seleccionada.
                        </p>
                    </div>
                @endforelse
            </div>
        @endif
    </div>
</x-filament-panels::page>