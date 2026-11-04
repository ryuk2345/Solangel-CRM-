<x-filament-panels::page>
    @include('filament.components.kardex-tabs', ['active' => 'valued'])

    <div class="space-y-6">
        {{-- Formulario de Filtros --}}
        <div class="bg-white dark:bg-gray-800 p-4 rounded-xl shadow-sm border border-gray-300 dark:border-gray-700">
            {{ $this->form }}
        </div>

        @php
            $report = $this->report;
            $canViewCosts = $this->canViewCosts();
            $consolidated = $report['consolidated'];
        @endphp

        {{-- Tarjetas del Consolidado del Periodo --}}
        @if($canViewCosts)
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3">
                <div class="bg-white dark:bg-gray-800 p-3.5 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700">
                    <span class="text-[11px] font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Inv. Inicial</span>
                    <div class="text-lg font-black text-gray-900 dark:text-white mt-1">
                        S/ {{ number_format($consolidated['initial_cost'], 2) }}
                    </div>
                </div>

                <div class="bg-white dark:bg-gray-800 p-3.5 rounded-xl shadow-sm border border-emerald-200 dark:border-emerald-800/60">
                    <span class="text-[11px] font-bold text-emerald-700 dark:text-emerald-400 uppercase tracking-wider">(+) Compras / Ent.</span>
                    <div class="text-lg font-black text-emerald-600 dark:text-emerald-400 mt-1">
                        S/ {{ number_format($consolidated['entries_cost'], 2) }}
                    </div>
                </div>

                <div class="bg-white dark:bg-gray-800 p-3.5 rounded-xl shadow-sm border border-amber-200 dark:border-amber-800/60">
                    <span class="text-[11px] font-bold text-amber-700 dark:text-amber-400 uppercase tracking-wider">(−) Inv. Final</span>
                    <div class="text-lg font-black text-amber-600 dark:text-amber-400 mt-1">
                        S/ {{ number_format($consolidated['final_cost'], 2) }}
                    </div>
                </div>

                <div class="bg-white dark:bg-gray-800 p-3.5 rounded-xl shadow-sm border border-indigo-200 dark:border-indigo-800/60">
                    <span class="text-[11px] font-bold text-indigo-700 dark:text-indigo-400 uppercase tracking-wider">(=) Costo de Ventas</span>
                    <div class="text-lg font-black text-indigo-600 dark:text-indigo-400 mt-1">
                        S/ {{ number_format($consolidated['cost_of_sales'], 2) }}
                    </div>
                </div>

                <div class="bg-white dark:bg-gray-800 p-3.5 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700">
                    <span class="text-[11px] font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Diferencia</span>
                    <div class="text-lg font-black {{ abs($consolidated['difference']) < 0.01 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400' }} mt-1">
                        S/ {{ number_format($consolidated['difference'], 2) }}
                    </div>
                </div>
            </div>
        @endif

        {{-- Bloques por Categoría y SKU --}}
        @forelse($report['categories'] as $catName => $blocks)
            <div class="space-y-4">
                <div class="flex items-center gap-2 border-b-2 border-gray-300 dark:border-gray-600 pb-2">
                    <x-filament::icon icon="heroicon-o-tag" class="w-5 h-5 text-primary-600" />
                    <h3 class="text-base font-black text-gray-900 dark:text-white uppercase tracking-wide">
                        Categoría: {{ $catName }}
                    </h3>
                    <span class="text-xs px-2.5 py-0.5 rounded-full bg-gray-200 dark:bg-gray-700 text-gray-800 dark:text-gray-200 font-bold">
                        {{ count($blocks) }} prendas
                    </span>
                </div>

                @foreach($blocks as $block)
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow border border-gray-300 dark:border-gray-600 overflow-hidden">
                        {{-- Cabecera del SKU --}}
                        <div class="bg-slate-100 dark:bg-gray-900 px-4 py-2.5 border-b border-gray-300 dark:border-gray-600 flex flex-wrap justify-between items-center gap-2">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <span class="font-mono font-bold text-primary-700 dark:text-primary-400 text-sm bg-primary-50 dark:bg-primary-950/60 px-2 py-0.5 rounded border border-primary-200 dark:border-primary-800">
                                    {{ $block['sku'] }}
                                </span>
                                <span class="font-bold text-gray-900 dark:text-white text-sm ml-1">
                                    {{ $block['product_name'] }}
                                </span>
                                <span class="text-xs bg-slate-200 dark:bg-slate-700 text-slate-800 dark:text-slate-200 font-semibold px-2 py-0.5 rounded">
                                    Talla: {{ $block['size_name'] }}
                                </span>
                                <span class="text-xs bg-slate-200 dark:bg-slate-700 text-slate-800 dark:text-slate-200 font-semibold px-2 py-0.5 rounded">
                                    Color: {{ $block['color_name'] }}
                                </span>
                            </div>
                            <div class="text-xs text-gray-600 dark:text-gray-300 flex items-center gap-1 font-semibold">
                                <x-filament::icon icon="heroicon-o-building-storefront" class="w-4 h-4 text-gray-500 dark:text-gray-400" />
                                <span>{{ $block['warehouse_name'] }}</span>
                            </div>
                        </div>

                        {{-- Tabla estilo Kardex con celdas y bordes visibles --}}
                        <div class="overflow-x-auto">
                            <table class="w-full text-xs text-left border-collapse border border-gray-300 dark:border-gray-600">
                                <thead>
                                    {{-- Fila 1 de encabezados --}}
                                    <tr class="bg-slate-100 dark:bg-gray-900 text-gray-900 dark:text-gray-100">
                                        <th rowspan="2" class="px-2 py-2 text-center border border-gray-300 dark:border-gray-600 font-bold w-10">Item</th>
                                        <th rowspan="2" class="px-2 py-2 text-center border border-gray-300 dark:border-gray-600 font-bold w-20">Fecha</th>
                                        <th rowspan="2" class="px-2 py-2 border border-gray-300 dark:border-gray-600 font-bold w-24">Guía Remisión</th>
                                        <th rowspan="2" class="px-2 py-2 border border-gray-300 dark:border-gray-600 font-bold w-24">Comprob. Pago</th>
                                        <th rowspan="2" class="px-3 py-2 border border-gray-300 dark:border-gray-600 font-bold min-w-[160px]">Detalle</th>
                                        <th colspan="3" class="px-2 py-1.5 text-center border border-gray-300 dark:border-gray-600 bg-emerald-100 dark:bg-emerald-950/60 text-emerald-900 dark:text-emerald-200 font-black tracking-wider">
                                            ENTRADAS
                                        </th>
                                        <th colspan="3" class="px-2 py-1.5 text-center border border-gray-300 dark:border-gray-600 bg-rose-100 dark:bg-rose-950/60 text-rose-900 dark:text-rose-200 font-black tracking-wider">
                                            SALIDAS
                                        </th>
                                        <th colspan="3" class="px-2 py-1.5 text-center border border-gray-300 dark:border-gray-600 bg-sky-100 dark:bg-sky-950/60 text-sky-900 dark:text-sky-200 font-black tracking-wider">
                                            SALDOS
                                        </th>
                                    </tr>
                                    {{-- Fila 2 de sub-encabezados --}}
                                    <tr class="bg-slate-50 dark:bg-gray-800 text-gray-700 dark:text-gray-300 font-bold">
                                        <th class="px-2 py-1 text-right w-12 border border-gray-300 dark:border-gray-600">Cant.</th>
                                        <th class="px-2 py-1 text-right w-16 border border-gray-300 dark:border-gray-600">P.U.</th>
                                        <th class="px-2 py-1 text-right w-20 border border-gray-300 dark:border-gray-600">P.T.</th>
                                        <th class="px-2 py-1 text-right w-12 border border-gray-300 dark:border-gray-600">Cant.</th>
                                        <th class="px-2 py-1 text-right w-16 border border-gray-300 dark:border-gray-600">P.U.</th>
                                        <th class="px-2 py-1 text-right w-20 border border-gray-300 dark:border-gray-600">P.T.</th>
                                        <th class="px-2 py-1 text-right w-12 border border-gray-300 dark:border-gray-600 text-gray-900 dark:text-white font-black">Cant.</th>
                                        <th class="px-2 py-1 text-right w-16 border border-gray-300 dark:border-gray-600">P.U.</th>
                                        <th class="px-2 py-1 text-right w-20 border border-gray-300 dark:border-gray-600 text-gray-900 dark:text-white font-black">P.T.</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {{-- Saldo Inicial --}}
                                    <tr class="bg-slate-100/70 dark:bg-gray-900/50 font-bold text-gray-900 dark:text-gray-100">
                                        <td class="px-2 py-2 text-center border border-gray-300 dark:border-gray-600 text-gray-400">—</td>
                                        <td class="px-2 py-2 text-center border border-gray-300 dark:border-gray-600 text-gray-400">—</td>
                                        <td class="px-2 py-2 border border-gray-300 dark:border-gray-600 text-gray-400">----</td>
                                        <td class="px-2 py-2 border border-gray-300 dark:border-gray-600 text-gray-400">----</td>
                                        <td class="px-3 py-2 border border-gray-300 dark:border-gray-600 font-bold text-gray-900 dark:text-white">
                                            {{ $block['initial']['label'] }}
                                        </td>
                                        <td class="border border-gray-300 dark:border-gray-600 bg-slate-50/50 dark:bg-transparent"></td>
                                        <td class="border border-gray-300 dark:border-gray-600 bg-slate-50/50 dark:bg-transparent"></td>
                                        <td class="border border-gray-300 dark:border-gray-600 bg-slate-50/50 dark:bg-transparent"></td>
                                        <td class="border border-gray-300 dark:border-gray-600 bg-slate-50/50 dark:bg-transparent"></td>
                                        <td class="border border-gray-300 dark:border-gray-600 bg-slate-50/50 dark:bg-transparent"></td>
                                        <td class="border border-gray-300 dark:border-gray-600 bg-slate-50/50 dark:bg-transparent"></td>
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

                                    {{-- Movimientos del mes --}}
                                    @foreach($block['rows'] as $r)
                                        <tr class="hover:bg-slate-50 dark:hover:bg-gray-700/50 text-gray-900 dark:text-gray-100">
                                            <td class="px-2 py-1.5 text-center border border-gray-300 dark:border-gray-600 font-medium text-gray-600 dark:text-gray-400">{{ $r['item'] }}</td>
                                            <td class="px-2 py-1.5 text-center border border-gray-300 dark:border-gray-600 text-gray-800 dark:text-gray-200">{{ $r['date'] }}</td>
                                            <td class="px-2 py-1.5 border border-gray-300 dark:border-gray-600 font-mono text-[11px] text-gray-700 dark:text-gray-300">{{ $r['guide'] }}</td>
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
                                    @endforeach

                                    {{-- Fila de Totales --}}
                                    <tr class="bg-slate-200/90 dark:bg-gray-900 font-black text-gray-900 dark:text-white border-t-2 border-b-2 border-gray-400 dark:border-gray-500">
                                        <td colspan="4" class="border border-gray-300 dark:border-gray-600"></td>
                                        <td class="px-3 py-2 border border-gray-300 dark:border-gray-600 uppercase tracking-wider text-xs font-black">
                                            COMPROBACIÓN: TOTALES
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

                        {{-- Cuadro de comprobación contable al pie (badges legibles de alto contraste) --}}
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
                @endforeach
            </div>
        @empty
            <div class="bg-white dark:bg-gray-800 p-8 rounded-xl text-center border border-gray-300 dark:border-gray-700">
                <x-filament::icon icon="heroicon-o-document-magnifying-glass" class="w-12 h-12 mx-auto text-gray-400 mb-2" />
                <p class="text-sm font-medium text-gray-600 dark:text-gray-300">
                    No se encontraron movimientos para los filtros seleccionados en este periodo.
                </p>
            </div>
        @endforelse
    </div>
</x-filament-panels::page>
