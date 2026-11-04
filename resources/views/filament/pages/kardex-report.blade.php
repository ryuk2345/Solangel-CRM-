<x-filament-panels::page>
    @include('filament.components.kardex-tabs', ['active' => 'valued'])

    <div class="space-y-6">
        {{-- Formulario de Filtros --}}
        <div class="bg-white dark:bg-gray-800 p-4 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700">
            {{ $this->form }}
        </div>

        @php
            $report = $this->report;
            $canViewCosts = $this->canViewCosts();
            $consolidated = $report['consolidated'];
        @endphp

        {{-- Tarjetas del Consolidado del Periodo --}}
        @if($canViewCosts)
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4">
                <div class="bg-white dark:bg-gray-800 p-4 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700">
                    <span class="text-xs font-medium text-gray-500 uppercase tracking-wider">Inv. Inicial</span>
                    <div class="text-xl font-bold text-gray-900 dark:text-white mt-1">
                        S/ {{ number_format($consolidated['initial_cost'], 2) }}
                    </div>
                </div>

                <div class="bg-white dark:bg-gray-800 p-4 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700">
                    <span class="text-xs font-medium text-emerald-600 uppercase tracking-wider">(+) Compras / Ent.</span>
                    <div class="text-xl font-bold text-emerald-600 mt-1">
                        S/ {{ number_format($consolidated['entries_cost'], 2) }}
                    </div>
                </div>

                <div class="bg-white dark:bg-gray-800 p-4 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700">
                    <span class="text-xs font-medium text-amber-600 uppercase tracking-wider">(−) Inv. Final</span>
                    <div class="text-xl font-bold text-amber-600 mt-1">
                        S/ {{ number_format($consolidated['final_cost'], 2) }}
                    </div>
                </div>

                <div class="bg-white dark:bg-gray-800 p-4 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700">
                    <span class="text-xs font-medium text-indigo-600 uppercase tracking-wider">(=) Costo de Ventas</span>
                    <div class="text-xl font-bold text-indigo-600 mt-1">
                        S/ {{ number_format($consolidated['cost_of_sales'], 2) }}
                    </div>
                </div>

                <div class="bg-white dark:bg-gray-800 p-4 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700">
                    <span class="text-xs font-medium text-gray-500 uppercase tracking-wider">Diferencia</span>
                    <div class="text-xl font-bold {{ abs($consolidated['difference']) < 0.01 ? 'text-emerald-600' : 'text-rose-600' }} mt-1">
                        S/ {{ number_format($consolidated['difference'], 2) }}
                    </div>
                </div>
            </div>
        @endif

        {{-- Bloques por Categoría y SKU --}}
        @forelse($report['categories'] as $catName => $blocks)
            <div class="space-y-4">
                <div class="flex items-center gap-2 border-b border-gray-300 dark:border-gray-700 pb-2">
                    <x-filament::icon icon="heroicon-o-tag" class="w-5 h-5 text-primary-600" />
                    <h3 class="text-lg font-bold text-gray-900 dark:text-white uppercase tracking-wide">
                        Categoría: {{ $catName }}
                    </h3>
                    <span class="text-xs px-2 py-0.5 rounded-full bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300">
                        {{ count($blocks) }} prendas
                    </span>
                </div>

                @foreach($blocks as $block)
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
                        {{-- Cabecera del SKU --}}
                        <div class="bg-gray-100 dark:bg-gray-900/60 px-4 py-3 border-b border-gray-200 dark:border-gray-700 flex flex-wrap justify-between items-center gap-2">
                            <div>
                                <span class="font-mono font-bold text-primary-600 dark:text-primary-400 text-sm">
                                    {{ $block['sku'] }}
                                </span>
                                <span class="text-gray-400 mx-1">·</span>
                                <span class="font-semibold text-gray-900 dark:text-white text-sm">
                                    {{ $block['product_name'] }}
                                </span>
                                <span class="text-gray-400 mx-1">·</span>
                                <span class="text-xs bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 px-2 py-0.5 rounded">
                                    Talla: {{ $block['size_name'] }}
                                </span>
                                <span class="text-xs bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 px-2 py-0.5 rounded ml-1">
                                    Color: {{ $block['color_name'] }}
                                </span>
                            </div>
                            <div class="text-xs text-gray-500 dark:text-gray-400 flex items-center gap-1">
                                <x-filament::icon icon="heroicon-o-building-storefront" class="w-4 h-4" />
                                <span>{{ $block['warehouse_name'] }}</span>
                            </div>
                        </div>

                        {{-- Tabla estilo Excel contable --}}
                        <div class="overflow-x-auto">
                            <table class="w-full text-xs text-left border-collapse">
                                <thead>
                                    <tr class="bg-gray-50 dark:bg-gray-800/80 text-gray-600 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
                                        <th rowspan="2" class="px-2 py-2 text-center border-r border-gray-200 dark:border-gray-700 w-10">Item</th>
                                        <th rowspan="2" class="px-2 py-2 text-center border-r border-gray-200 dark:border-gray-700 w-20">Fecha</th>
                                        <th rowspan="2" class="px-2 py-2 border-r border-gray-200 dark:border-gray-700 w-24">Guía Remisión</th>
                                        <th rowspan="2" class="px-2 py-2 border-r border-gray-200 dark:border-gray-700 w-24">Comprob. Pago</th>
                                        <th rowspan="2" class="px-3 py-2 border-r border-gray-200 dark:border-gray-700">Detalle</th>
                                        <th colspan="3" class="px-2 py-1 text-center border-r border-gray-200 dark:border-gray-700 bg-emerald-50 dark:bg-emerald-950/20 text-emerald-800 dark:text-emerald-300 font-semibold">
                                            ENTRADAS
                                        </th>
                                        <th colspan="3" class="px-2 py-1 text-center border-r border-gray-200 dark:border-gray-700 bg-rose-50 dark:bg-rose-950/20 text-rose-800 dark:text-rose-300 font-semibold">
                                            SALIDAS
                                        </th>
                                        <th colspan="3" class="px-2 py-1 text-center bg-blue-50 dark:bg-blue-950/20 text-blue-800 dark:text-blue-300 font-semibold">
                                            SALDOS
                                        </th>
                                    </tr>
                                    <tr class="bg-gray-50 dark:bg-gray-800/80 text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700 font-medium">
                                        <th class="px-2 py-1 text-right w-12 border-r border-gray-200 dark:border-gray-700">Cant.</th>
                                        <th class="px-2 py-1 text-right w-16 border-r border-gray-200 dark:border-gray-700">P.U.</th>
                                        <th class="px-2 py-1 text-right w-20 border-r border-gray-200 dark:border-gray-700">P.T.</th>
                                        <th class="px-2 py-1 text-right w-12 border-r border-gray-200 dark:border-gray-700">Cant.</th>
                                        <th class="px-2 py-1 text-right w-16 border-r border-gray-200 dark:border-gray-700">P.U.</th>
                                        <th class="px-2 py-1 text-right w-20 border-r border-gray-200 dark:border-gray-700">P.T.</th>
                                        <th class="px-2 py-1 text-right w-12 border-r border-gray-200 dark:border-gray-700 font-bold">Cant.</th>
                                        <th class="px-2 py-1 text-right w-16 border-r border-gray-200 dark:border-gray-700">P.U.</th>
                                        <th class="px-2 py-1 text-right w-20 font-bold">P.T.</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                    {{-- Saldo Inicial --}}
                                    <tr class="bg-gray-50/50 dark:bg-gray-900/30 font-medium text-gray-700 dark:text-gray-300">
                                        <td class="px-2 py-2 text-center border-r border-gray-200 dark:border-gray-700 text-gray-400">—</td>
                                        <td class="px-2 py-2 text-center border-r border-gray-200 dark:border-gray-700 text-gray-400">—</td>
                                        <td class="px-2 py-2 border-r border-gray-200 dark:border-gray-700 text-gray-400">----</td>
                                        <td class="px-2 py-2 border-r border-gray-200 dark:border-gray-700 text-gray-400">----</td>
                                        <td class="px-3 py-2 border-r border-gray-200 dark:border-gray-700 font-semibold text-gray-800 dark:text-gray-200">
                                            {{ $block['initial']['label'] }}
                                        </td>
                                        <td colspan="3" class="border-r border-gray-200 dark:border-gray-700"></td>
                                        <td colspan="3" class="border-r border-gray-200 dark:border-gray-700"></td>
                                        <td class="px-2 py-2 text-right border-r border-gray-200 dark:border-gray-700 font-bold">
                                            {{ $block['initial']['qty'] }}
                                        </td>
                                        <td class="px-2 py-2 text-right border-r border-gray-200 dark:border-gray-700">
                                            {{ $canViewCosts ? number_format($block['initial']['unit_cost'], 4) : '—' }}
                                        </td>
                                        <td class="px-2 py-2 text-right font-bold">
                                            {{ $canViewCosts ? number_format($block['initial']['total_cost'], 2) : '—' }}
                                        </td>
                                    </tr>

                                    {{-- Movimientos del mes --}}
                                    @foreach($block['rows'] as $r)
                                        <tr class="hover:bg-gray-50/80 dark:hover:bg-gray-700/30">
                                            <td class="px-2 py-1.5 text-center border-r border-gray-200 dark:border-gray-700 text-gray-500">{{ $r['item'] }}</td>
                                            <td class="px-2 py-1.5 text-center border-r border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400">{{ $r['date'] }}</td>
                                            <td class="px-2 py-1.5 border-r border-gray-200 dark:border-gray-700 font-mono text-[11px]">{{ $r['guide'] }}</td>
                                            <td class="px-2 py-1.5 border-r border-gray-200 dark:border-gray-700 font-mono text-[11px] font-semibold">{{ $r['doc'] }}</td>
                                            <td class="px-3 py-1.5 border-r border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300">{{ $r['detail'] }}</td>

                                            {{-- Entradas --}}
                                            <td class="px-2 py-1.5 text-right border-r border-gray-200 dark:border-gray-700 {{ ($r['entry_qty'] ?? 0) < 0 ? 'text-rose-600 font-semibold' : '' }}">
                                                {{ $r['entry_qty'] !== null ? $r['entry_qty'] : '' }}
                                            </td>
                                            <td class="px-2 py-1.5 text-right border-r border-gray-200 dark:border-gray-700">
                                                {{ $r['entry_unit'] !== null ? ($canViewCosts ? number_format($r['entry_unit'], 4) : '—') : '' }}
                                            </td>
                                            <td class="px-2 py-1.5 text-right border-r border-gray-200 dark:border-gray-700 {{ ($r['entry_total'] ?? 0) < 0 ? 'text-rose-600 font-semibold' : '' }}">
                                                {{ $r['entry_total'] !== null ? ($canViewCosts ? number_format($r['entry_total'], 2) : '—') : '' }}
                                            </td>

                                            {{-- Salidas --}}
                                            <td class="px-2 py-1.5 text-right border-r border-gray-200 dark:border-gray-700 {{ ($r['exit_qty'] ?? 0) < 0 ? 'text-rose-600 font-semibold' : '' }}">
                                                {{ $r['exit_qty'] !== null ? $r['exit_qty'] : '' }}
                                            </td>
                                            <td class="px-2 py-1.5 text-right border-r border-gray-200 dark:border-gray-700">
                                                {{ $r['exit_unit'] !== null ? ($canViewCosts ? number_format($r['exit_unit'], 4) : '—') : '' }}
                                            </td>
                                            <td class="px-2 py-1.5 text-right border-r border-gray-200 dark:border-gray-700 {{ ($r['exit_total'] ?? 0) < 0 ? 'text-rose-600 font-semibold' : '' }}">
                                                {{ $r['exit_total'] !== null ? ($canViewCosts ? number_format($r['exit_total'], 2) : '—') : '' }}
                                            </td>

                                            {{-- Saldos --}}
                                            <td class="px-2 py-1.5 text-right border-r border-gray-200 dark:border-gray-700 font-bold">
                                                {{ $r['balance_qty'] }}
                                            </td>
                                            <td class="px-2 py-1.5 text-right border-r border-gray-200 dark:border-gray-700">
                                                {{ $canViewCosts ? number_format($r['balance_unit'], 4) : '—' }}
                                            </td>
                                            <td class="px-2 py-1.5 text-right font-bold">
                                                {{ $canViewCosts ? number_format($r['balance_total'], 2) : '—' }}
                                            </td>
                                        </tr>
                                    @endforeach

                                    {{-- Fila de Totales --}}
                                    <tr class="bg-gray-100/70 dark:bg-gray-800 font-bold border-t-2 border-b border-gray-300 dark:border-gray-600">
                                        <td colspan="4" class="border-r border-gray-200 dark:border-gray-700"></td>
                                        <td class="px-3 py-2 border-r border-gray-200 dark:border-gray-700 uppercase tracking-wider text-xs">
                                            COMPROBACIÓN: TOTALES
                                        </td>
                                        <td class="px-2 py-2 text-right border-r border-gray-200 dark:border-gray-700">
                                            {{ $block['totals']['entries_qty'] }}
                                        </td>
                                        <td class="border-r border-gray-200 dark:border-gray-700"></td>
                                        <td class="px-2 py-2 text-right border-r border-gray-200 dark:border-gray-700 text-emerald-700 dark:text-emerald-400">
                                            {{ $canViewCosts ? number_format($block['totals']['entries_cost'], 2) : '—' }}
                                        </td>
                                        <td class="px-2 py-2 text-right border-r border-gray-200 dark:border-gray-700">
                                            {{ $block['totals']['exits_qty'] }}
                                        </td>
                                        <td class="border-r border-gray-200 dark:border-gray-700"></td>
                                        <td class="px-2 py-2 text-right border-r border-gray-200 dark:border-gray-700 text-rose-700 dark:text-rose-400">
                                            {{ $canViewCosts ? number_format($block['totals']['exits_cost'], 2) : '—' }}
                                        </td>
                                        <td class="px-2 py-2 text-right border-r border-gray-200 dark:border-gray-700">
                                            {{ $block['totals']['balance_qty'] }}
                                        </td>
                                        <td class="border-r border-gray-200 dark:border-gray-700"></td>
                                        <td class="px-2 py-2 text-right font-black">
                                            {{ $canViewCosts ? number_format($block['totals']['balance_total'], 2) : '—' }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        {{-- Cuadro de comprobación contable al pie --}}
                        @if($canViewCosts)
                            <div class="bg-gray-50 dark:bg-gray-900/40 p-3 border-t border-gray-200 dark:border-gray-700 flex flex-wrap items-center justify-end gap-x-6 gap-y-2 text-xs">
                                <div>
                                    <span class="text-gray-500">INV. INICIAL:</span>
                                    <span class="font-semibold ml-1">S/ {{ number_format($block['summary']['initial_cost'], 2) }}</span>
                                </div>
                                <div>
                                    <span class="text-emerald-600">(+) COMPRAS:</span>
                                    <span class="font-semibold ml-1 text-emerald-600">S/ {{ number_format($block['summary']['entries_cost'], 2) }}</span>
                                </div>
                                <div>
                                    <span class="text-amber-600">(−) INV FINAL:</span>
                                    <span class="font-semibold ml-1 text-amber-600">S/ {{ number_format(-1 * $block['summary']['final_cost'], 2) }}</span>
                                </div>
                                <div class="border-l border-gray-300 dark:border-gray-600 pl-4">
                                    <span class="text-indigo-600 font-bold">(=) COSTO DE VENTAS:</span>
                                    <span class="font-bold ml-1 text-indigo-600">S/ {{ number_format($block['summary']['cost_of_sales'], 2) }}</span>
                                </div>
                                <div>
                                    <span class="text-gray-500">DIFERENCIA:</span>
                                    <span class="font-bold ml-1 {{ abs($block['summary']['difference']) < 0.01 ? 'text-emerald-600' : 'text-rose-600' }}">
                                        S/ {{ number_format($block['summary']['difference'], 2) }}
                                    </span>
                                </div>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @empty
            <div class="bg-white dark:bg-gray-800 p-8 rounded-xl text-center border border-gray-200 dark:border-gray-700">
                <x-filament::icon icon="heroicon-o-document-magnifying-glass" class="w-12 h-12 mx-auto text-gray-400 mb-2" />
                <p class="text-sm font-medium text-gray-600 dark:text-gray-300">
                    No se encontraron movimientos para los filtros seleccionados en este periodo.
                </p>
            </div>
        @endforelse
    </div>
</x-filament-panels::page>

