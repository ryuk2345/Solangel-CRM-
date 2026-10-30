<x-filament-panels::page>
    <div class="space-y-6">

        {{-- Instrucciones --}}
        <x-filament::section>
            <x-slot name="heading">📋 Instrucciones</x-slot>
            <div class="text-sm text-gray-600 dark:text-gray-400 space-y-1">
                <p>Sube un archivo <strong>CSV</strong> con las siguientes columnas (la primera fila debe ser el encabezado):</p>
                <div class="mt-2 overflow-x-auto">
                    <table class="text-xs border-collapse border border-gray-300 dark:border-gray-600">
                        <thead class="bg-gray-100 dark:bg-gray-700">
                            <tr>
                                <th class="border border-gray-300 dark:border-gray-600 px-3 py-1">Columna</th>
                                <th class="border border-gray-300 dark:border-gray-600 px-3 py-1">Requerido</th>
                                <th class="border border-gray-300 dark:border-gray-600 px-3 py-1">Descripción</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach([
                                ['DESCRIPCION', '✅ Sí', 'Nombre del producto'],
                                ['Categoria', 'No', 'Se crea automáticamente si no existe'],
                                ['Marca', 'No', 'Se crea automáticamente si no existe'],
                                ['OEM', 'No', 'Código de referencia / SKU'],
                                ['Fabricante', 'No', 'Nombre del fabricante'],
                                ['Compatibilidad', 'No', 'Tallas, modelos compatibles'],
                                ['Precio Venta', '✅ Sí', 'Precio de venta (número)'],
                                ['Precio Compra', 'No', 'Precio de compra (número)'],
                                ['Unidad', 'No', 'Ej: NIU, ZZ (por defecto NIU)'],
                                ['Stock', 'No', 'Cantidad en almacén (número entero)'],
                            ] as [$col, $req, $desc])
                            <tr>
                                <td class="border border-gray-300 dark:border-gray-600 px-3 py-1 font-mono font-bold">{{ $col }}</td>
                                <td class="border border-gray-300 dark:border-gray-600 px-3 py-1 text-center">{{ $req }}</td>
                                <td class="border border-gray-300 dark:border-gray-600 px-3 py-1">{{ $desc }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </x-filament::section>

        {{-- Formulario de carga --}}
        @if(!$this->imported)
        <x-filament::section>
            <x-slot name="heading">📁 Cargar Archivo</x-slot>

            <form wire:submit="import">
                {{ $this->form }}

                <div class="mt-4">
                    <x-filament::button type="submit" size="lg" color="success" icon="heroicon-o-arrow-up-tray">
                        Importar Productos Ahora
                    </x-filament::button>
                </div>
            </form>
        </x-filament::section>
        @endif

        {{-- Resultados --}}
        @if($this->imported && !empty($this->results))
        <x-filament::section>
            <x-slot name="heading">📊 Resultados de la Importación</x-slot>

            <div class="grid grid-cols-2 gap-4 mb-4">
                <div class="bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg p-4 text-center">
                    <div class="text-3xl font-bold text-green-600 dark:text-green-400">{{ $this->results['imported'] }}</div>
                    <div class="text-sm text-green-700 dark:text-green-300">Productos importados ✅</div>
                </div>
                <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-4 text-center">
                    <div class="text-3xl font-bold text-red-600 dark:text-red-400">{{ $this->results['skipped'] }}</div>
                    <div class="text-sm text-red-700 dark:text-red-300">Filas omitidas ⚠️</div>
                </div>
            </div>

            @if(!empty($this->results['errors']))
            <div class="mt-4">
                <p class="font-semibold text-red-600 mb-2">Errores encontrados:</p>
                <ul class="text-sm space-y-1">
                    @foreach($this->results['errors'] as $error)
                    <li class="text-red-500">• {{ $error }}</li>
                    @endforeach
                </ul>
            </div>
            @endif

            <div class="mt-4">
                <x-filament::button wire:click="$set('imported', false); $set('results', [])" color="gray">
                    Importar otro archivo
                </x-filament::button>
                <x-filament::button tag="a" href="{{ route('filament.admin.resources.products.index') }}" color="primary" class="ml-2">
                    Ver Productos
                </x-filament::button>
            </div>
        </x-filament::section>
        @endif

    </div>
</x-filament-panels::page>
