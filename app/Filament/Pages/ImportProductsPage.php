<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Filament\Forms\Form;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use App\Models\Product;
use App\Models\Category;
use App\Models\Brand;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImportProductsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-up-tray';
    protected static ?string $navigationLabel = 'Importar Productos';
    protected static ?string $title = 'Importar Productos desde Excel/CSV';
    protected static ?string $navigationGroup = 'Administración';
    protected static ?int $navigationSort = 10;

    protected static string $view = 'filament.pages.import-products';

    public ?array $data = [];
    public array $results = [];
    public bool $imported = false;

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                FileUpload::make('csv_file')
                    ->label('Archivo CSV o Excel')
                    ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel', 'application/csv', '.csv'])
                    ->disk('local')
                    ->directory('imports')
                    ->required(),

                \Filament\Forms\Components\Select::make('warehouse_id')
                    ->label('Almacén de Destino para el Stock')
                    ->options(function () {
                        $user = auth()->user();
                        $query = \App\Models\Warehouse::with('branch')->where('is_active', true);
                        if ($user && !$user->hasRole('super_admin')) {
                            $query->where('branch_id', $user->branch_id ?? 1);
                        }
                        return $query->get()
                            ->mapWithKeys(function ($w) {
                                $branchName = $w->branch?->name ?? 'Tienda';
                                $defaultLabel = $w->is_default ? ' (Por Defecto)' : '';
                                return [$w->id => "{$branchName} - {$w->name}{$defaultLabel}"];
                            });
                    })
                    ->default(function () {
                        $user = auth()->user();
                        $query = \App\Models\Warehouse::where('is_active', true);
                        if ($user && !$user->hasRole('super_admin')) {
                            $query->where('branch_id', $user->branch_id ?? 1);
                        }
                        return $query->where('is_default', true)->value('id') ?? $query->value('id') ?? 1;
                    })
                    ->required()
                    ->helperText('Solo este almacén recibirá el stock del archivo. En los demás almacenes se mantendrá el stock actual.'),
            ])
            ->statePath('data');
    }

    public function import(): void
    {
        $this->validate();

        // Filament/Livewire FileUpload puede devolver string o array
        $fileData = $this->data['csv_file'];
        $file = is_array($fileData) ? (array_values($fileData)[0] ?? null) : $fileData;

        if (!$file) {
            Notification::make()->title('Error')->body('No se seleccionó ningún archivo.')->danger()->send();
            return;
        }

        // Resolver el path real del archivo en cualquier ubicación posible
        $path = $this->resolveUploadPath($file);

        if (!$path || !file_exists($path)) {
            Notification::make()->title('Error')->body('No se encontró el archivo subido. Intenta de nuevo.')->danger()->send();
            return;
        }

        $handle = fopen($path, 'r');
        if (!$handle) {
            Notification::make()->title('Error')->body('No se pudo abrir el archivo para lectura.')->danger()->send();
            return;
        }

        // Detectar delimitador leyendo la primera línea
        $delimiter = ',';
        $firstLine = fgets($handle);
        if ($firstLine !== false) {
            $semicolons = substr_count($firstLine, ';');
            $commas = substr_count($firstLine, ',');
            if ($semicolons > $commas) {
                $delimiter = ';';
            }
        }
        rewind($handle);

        $headers = null;
        $imported = 0;
        $skipped  = 0;
        $errors   = [];
        $row_num  = 0;

        // Pre-cargar datos para optimizar consultas en memoria
        $user = auth()->user();
        $userBranchId = ($user && !$user->hasRole('super_admin')) ? ($user->branch_id ?? 1) : null;

        $branches = \App\Models\Branch::all();
        $allWarehouses = \App\Models\Warehouse::where('is_active', true)->get();
        $targetWarehouseId = (int) ($this->data['warehouse_id'] ?? 1);
        $targetWarehouse = $allWarehouses->firstWhere('id', $targetWarehouseId);

        if ($userBranchId) {
            if (!$targetWarehouse || $targetWarehouse->branch_id != $userBranchId) {
                $targetWarehouse = $allWarehouses->where('branch_id', $userBranchId)->firstWhere('is_default', true)
                    ?? $allWarehouses->where('branch_id', $userBranchId)->first();
            }
        }

        if (!$targetWarehouse) {
            $targetWarehouse = $allWarehouses->firstWhere('branch_id', 1) ?? $allWarehouses->first();
        }

        $targetWarehouseId = $targetWarehouse ? $targetWarehouse->id : 1;
        $targetBranchId    = $targetWarehouse ? $targetWarehouse->branch_id : 1;
        
        $categoriesCache = [];
        foreach (Category::all() as $cat) {
            $categoriesCache[Str::slug($cat->name)] = $cat->id;
        }

        $brandsCache = [];
        foreach (Brand::all() as $brand) {
            $brandsCache[Str::slug($brand->name)] = $brand->id;
        }

        // Desactivar eventos de Eloquent para optimización extrema y evitar bloqueos en bases de datos grandes
        \Illuminate\Database\Eloquent\Model::withoutEvents(function () use ($handle, $delimiter, &$headers, &$imported, &$skipped, &$errors, &$row_num, $branches, $allWarehouses, $targetWarehouseId, $targetBranchId, &$categoriesCache, &$brandsCache) {
            while (($row = fgetcsv($handle, 2000, $delimiter)) !== false) {
                $row_num++;

                // Convertir codificación de la fila a UTF-8 si es necesario (ej. Windows-1252 desde Excel)
                $row = array_map(function ($value) {
                    if ($value === null || $value === '') {
                        return '';
                    }
                    if (!mb_check_encoding($value, 'UTF-8')) {
                        return mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
                    }
                    return $value;
                }, $row);

                // Primera fila = encabezados
                if ($headers === null) {
                    $headers = array_map('trim', $row);
                    continue;
                }

                // Ignorar filas vacías
                if (empty(array_filter($row))) continue;

                // Mapear columna → valor con heurística de alineación para filas desplazadas
                $data = $this->mapRowData($row, $headers);

                // DESCRIPCION es obligatorio
                $name = $this->getValueByKeys($data, ['DESCRIPCION', 'descripcion', 'name', 'nombre']);
                if (empty($name)) {
                    $skipped++;
                    continue;
                }

                // Limpieza y parseo de Precio de venta
                $salePriceRaw = $this->getValueByKeys($data, ['Precio Venta', 'precio_venta', 'PRECIO VENTA', 'pvp', 'precio_vent', 'preciovent']);
                $salePriceClean = str_replace(['S/', '$', ' ', 'S/.', 's/', 's/.'], '', $salePriceRaw);
                if (str_contains($salePriceClean, ',') && str_contains($salePriceClean, '.')) {
                    $salePriceClean = str_replace(',', '', $salePriceClean);
                } elseif (str_contains($salePriceClean, ',')) {
                    $salePriceClean = str_replace(',', '.', $salePriceClean);
                }
                $salePrice = (float) $salePriceClean;
                if ($salePrice <= 0) {
                    $salePrice = 0.00;
                }

                // Limpieza y parseo de Precio de compra
                $purchasePriceRaw = $this->getValueByKeys($data, ['Precio Compra', 'precio_compra', 'PRECIO COMPRA', 'precio_compr', 'preciocompr'], '0');
                $purchasePriceClean = str_replace(['S/', '$', ' ', 'S/.', 's/', 's/.'], '', $purchasePriceRaw);
                if (str_contains($purchasePriceClean, ',') && str_contains($purchasePriceClean, '.')) {
                    $purchasePriceClean = str_replace(',', '', $purchasePriceClean);
                } elseif (str_contains($purchasePriceClean, ',')) {
                    $purchasePriceClean = str_replace(',', '.', $purchasePriceClean);
                }
                $purchasePrice = (float) $purchasePriceClean;

                try {
                    DB::beginTransaction();

                    // Categoría (auto-crear con caché de memoria)
                    $categoryId = null;
                    $catName = trim($this->getValueByKeys($data, ['Categoria', 'categoria', 'category']));
                    if (!empty($catName)) {
                        $catSlug = Str::slug($catName);
                        if (!isset($categoriesCache[$catSlug])) {
                            $cat = Category::create([
                                'name' => $catName,
                                'slug' => $catSlug
                            ]);
                            $categoriesCache[$catSlug] = $cat->id;
                        }
                        $categoryId = $categoriesCache[$catSlug];
                    }

                    // Marca (auto-crear con caché de memoria)
                    $brandId = null;
                    $brandName = trim($this->getValueByKeys($data, ['Marca', 'marca', 'brand']));
                    if (!empty($brandName)) {
                        $brandSlug = Str::slug($brandName);
                        if (!isset($brandsCache[$brandSlug])) {
                            $brand = Brand::create(['name' => $brandName]);
                            $brandsCache[$brandSlug] = $brand->id;
                        }
                        $brandId = $brandsCache[$brandSlug];
                    }

                    // SKU / OEM
                    $oemCode = trim($this->getValueByKeys($data, ['OEM', 'oem', 'codigo_oem', 'codigo oem']));

                    // --- BÚSQUEDA DE PRODUCTO Y VARIANTE EXISTENTE ---
                    $product = null;
                    $variant = null;

                    if (!empty($oemCode)) {
                        $variant = ProductVariant::where('sku', $oemCode)->first();
                        if ($variant) {
                            $product = Product::find($variant->product_id);
                        } else {
                            $product = Product::where('oem_code', $oemCode)->first();
                            if ($product) {
                                $variant = ProductVariant::where('product_id', $product->id)->first();
                            }
                        }
                    } else {
                        $product = Product::where('name', $name)
                            ->where(function($q) {
                                $q->whereNull('oem_code')->orWhere('oem_code', '');
                            })->first();
                        if ($product) {
                            $variant = ProductVariant::where('product_id', $product->id)->first();
                        }
                    }

                    // CASO 1: SKU NUEVO (Ni producto ni variante existen)
                    if (!$product && !$variant) {
                        $product = Product::create([
                            'name'           => $name,
                            'category_id'    => $categoryId,
                            'brand_id'       => $brandId,
                            'brand'          => $brandName,
                            'oem_code'       => $oemCode,
                            'sale_price'     => $salePrice,
                            'purchase_price' => $purchasePrice,
                            'unit_code'      => !empty($this->getValueByKeys($data, ['Unidad', 'unidad', 'unidad_de_medida', 'unidad_de_medid'])) ? $this->getValueByKeys($data, ['Unidad', 'unidad', 'unidad_de_medida', 'unidad_de_medid']) : 'NIU',
                            'description'    => $this->getValueByKeys($data, ['Fabricante', 'fabricante', 'fabricant', 'description']),
                        ]);

                        // Habilitar únicamente en la tienda del almacén de destino
                        $product->branches()->sync([$targetBranchId]);

                        $sku = !empty($oemCode) ? $oemCode : 'REP-' . str_pad($product->id, 5, '0', STR_PAD_LEFT);
                        $compat = $this->getValueByKeys($data, ['Compatibilidad', 'compatibilidad', 'compatibility'], 'Único');

                        $variant = ProductVariant::create([
                            'sku'            => $sku,
                            'product_id'     => $product->id,
                            'size'           => !empty($compat) ? $compat : 'Único',
                            'color_name'     => !empty($brandName) ? $brandName : 'Estándar',
                            'sale_price'     => $salePrice,
                            'purchase_price' => $purchasePrice,
                        ]);

                        if (!$product->is_service) {
                            $stockRaw = $this->getValueByKeys($data, ['Stock', 'stock', 'stoc', 'cantidad']);
                            $stock = (int) $stockRaw;

                            DB::table('inventories')->updateOrInsert(
                                [
                                    'warehouse_id'       => $targetWarehouseId,
                                    'product_variant_id' => $variant->id,
                                ],
                                [
                                    'branch_id'          => $targetBranchId,
                                    'stock'              => $stock,
                                    'updated_at'         => now(),
                                    'created_at'         => now(),
                                ]
                            );

                            // Asegurar 0 solo en otros almacenes de la MISMA tienda
                            $sameBranchWarehouses = $allWarehouses->where('branch_id', $targetBranchId)->where('id', '!=', $targetWarehouseId);
                            foreach ($sameBranchWarehouses as $w) {
                                $exists = DB::table('inventories')
                                    ->where('warehouse_id', $w->id)
                                    ->where('product_variant_id', $variant->id)
                                    ->exists();
                                if (!$exists) {
                                    DB::table('inventories')->insert([
                                        'warehouse_id'       => $w->id,
                                        'branch_id'          => $w->branch_id,
                                        'product_variant_id' => $variant->id,
                                        'stock'              => 0,
                                        'created_at'         => now(),
                                        'updated_at'         => now(),
                                    ]);
                                }
                            }
                        }

                        DB::commit();
                        $imported++;
                        continue;
                    }

                    // Si existe variante sin producto
                    if (!$product && $variant) {
                        $product = Product::find($variant->product_id);
                    }
                    if (!$product) {
                        DB::rollBack();
                        $errors[] = "Fila $row_num ($name): Error de integridad, variante sin producto padre.";
                        $skipped++;
                        continue;
                    }

                    $productBranchIds = DB::table('branch_product')
                        ->where('product_id', $product->id)
                        ->pluck('branch_id')
                        ->toArray();

                    $sku = !empty($oemCode) ? $oemCode : ($variant ? $variant->sku : ('REP-' . str_pad($product->id, 5, '0', STR_PAD_LEFT)));
                    $compat = $this->getValueByKeys($data, ['Compatibilidad', 'compatibilidad', 'compatibility'], 'Único');

                    // CASO 4: SKU existe pero NO está habilitado en la tienda de destino -> Omitir y reportar
                    if (!in_array($targetBranchId, $productBranchIds)) {
                        DB::rollBack();
                        $branchName = $branches->firstWhere('id', $targetBranchId)?->name ?? "Tienda #{$targetBranchId}";
                        $errors[] = "Fila $row_num: SKU '{$sku}' ({$name}) existe en el sistema pero no está habilitado en la tienda {$branchName}. Omitido.";
                        $skipped++;
                        continue;
                    }

                    $isShared = count($productBranchIds) > 1;

                    if (!$isShared) {
                        // CASO 2: SKU existente habilitado SOLO en la tienda de destino -> Actualizar datos maestros y stock
                        $product->update([
                            'category_id'    => $categoryId,
                            'brand_id'       => $brandId,
                            'brand'          => !empty($brandName) ? $brandName : $product->brand,
                            'sale_price'     => $salePrice,
                            'purchase_price' => $purchasePrice,
                            'unit_code'      => !empty($this->getValueByKeys($data, ['Unidad', 'unidad', 'unidad_de_medida', 'unidad_de_medid'])) ? $this->getValueByKeys($data, ['Unidad', 'unidad', 'unidad_de_medida', 'unidad_de_medid']) : 'NIU',
                            'description'    => $this->getValueByKeys($data, ['Fabricante', 'fabricante', 'fabricant', 'description']),
                        ]);

                        if ($variant) {
                            $variant->update([
                                'product_id'     => $product->id,
                                'size'           => !empty($compat) ? $compat : 'Único',
                                'color_name'     => !empty($brandName) ? $brandName : 'Estándar',
                                'sale_price'     => $salePrice,
                                'purchase_price' => $purchasePrice,
                            ]);
                        } else {
                            $variant = ProductVariant::create([
                                'sku'            => $sku,
                                'product_id'     => $product->id,
                                'size'           => !empty($compat) ? $compat : 'Único',
                                'color_name'     => !empty($brandName) ? $brandName : 'Estándar',
                                'sale_price'     => $salePrice,
                                'purchase_price' => $purchasePrice,
                            ]);
                        }
                    } else {
                        // CASO 3: SKU compartido entre tiendas -> Actualizar ÚNICAMENTE stock en almacén de destino (datos maestros intactos)
                        if (!$variant) {
                            $variant = ProductVariant::create([
                                'sku'            => $sku,
                                'product_id'     => $product->id,
                                'size'           => !empty($compat) ? $compat : 'Único',
                                'color_name'     => !empty($brandName) ? $brandName : 'Estándar',
                                'sale_price'     => $product->sale_price ?? $salePrice,
                                'purchase_price' => $product->purchase_price ?? $purchasePrice,
                            ]);
                        }
                    }

                    // Stock (Solo si no es un servicio)
                    if (!$product->is_service) {
                        $stockRaw = $this->getValueByKeys($data, ['Stock', 'stock', 'stoc', 'cantidad']);
                        $stock = (int) $stockRaw;

                        // Asignar el stock del archivo SOLO al almacén de destino seleccionado
                        DB::table('inventories')->updateOrInsert(
                            [
                                'warehouse_id'       => $targetWarehouseId,
                                'product_variant_id' => $variant->id,
                            ],
                            [
                                'branch_id'          => $targetBranchId,
                                'stock'              => $stock,
                                'updated_at'         => now(),
                                'created_at'         => now(),
                            ]
                        );

                        // Asegurar fila en 0 solo en otros almacenes de la MISMA tienda
                        $sameBranchWarehouses = $allWarehouses->where('branch_id', $targetBranchId)->where('id', '!=', $targetWarehouseId);
                        foreach ($sameBranchWarehouses as $w) {
                            $exists = DB::table('inventories')
                                ->where('warehouse_id', $w->id)
                                ->where('product_variant_id', $variant->id)
                                ->exists();
                            if (!$exists) {
                                DB::table('inventories')->insert([
                                    'warehouse_id'       => $w->id,
                                    'branch_id'          => $w->branch_id,
                                    'product_variant_id' => $variant->id,
                                    'stock'              => 0,
                                    'created_at'         => now(),
                                    'updated_at'         => now(),
                                ]);
                            }
                        }
                    }

                    DB::commit();
                    $imported++;

                    if ($isShared) {
                        $errors[] = "ℹ️ Fila $row_num: SKU '{$sku}' ({$name}): Producto compartido con otras tiendas. Se actualizó solo el stock en este almacén (datos maestros intactos).";
                    }

                } catch (\Exception $e) {
                    DB::rollBack();
                    $errors[] = "Fila $row_num ($name): " . $e->getMessage();
                    $skipped++;
                }
            }
        });

        fclose($handle);
        Storage::disk('local')->delete('imports/' . $file); // $file ya es string aquí

        $this->results = [
            'imported' => $imported,
            'skipped'  => $skipped,
            'errors'   => $errors,
        ];
        $this->imported = true;

        Notification::make()
            ->title("✅ Importación completada")
            ->body("$imported productos importados. $skipped omitidos.")
            ->success()
            ->send();
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * Resuelve el path real del archivo subido por Livewire/Filament
     * Puede estar en: /tmp/, livewire-tmp/, storage/app/imports/, etc.
     */
    private function resolveUploadPath(string $file): ?string
    {
        // 1. Path absoluto directo (ej: /tmp/phpXXXXX)
        if (str_starts_with($file, '/') && file_exists($file)) {
            return $file;
        }

        // 2. En disco local - carpeta imports
        $p = Storage::disk('local')->path('imports/' . $file);
        if (file_exists($p)) return $p;

        // 3. En Livewire temp (disco local)
        $p = Storage::disk('local')->path('livewire-tmp/' . $file);
        if (file_exists($p)) return $p;

        // 4. En disco public
        $p = Storage::disk('public')->path($file);
        if (file_exists($p)) return $p;

        // 5. Buscar en storage/app recursivamente
        $base = storage_path('app');
        foreach (glob($base . '/*/' . basename($file)) as $found) {
            if (file_exists($found)) return $found;
        }

        return null;
    }

    private function getValueByKeys(array $data, array $keys, $default = '')
    {
        $normalizedData = [];
        foreach ($data as $key => $val) {
            $normKey = strtolower(trim($key));
            $normKey = str_replace(
                ['á', 'é', 'í', 'ó', 'ú', 'ñ', 'ü'],
                ['a', 'e', 'i', 'o', 'u', 'n', 'u'],
                $normKey
            );
            $normKey = str_replace(['_', ' '], '', $normKey);
            $normalizedData[$normKey] = $val;
        }

        foreach ($keys as $k) {
            $normK = strtolower(trim($k));
            $normK = str_replace(
                ['á', 'é', 'í', 'ó', 'ú', 'ñ', 'ü'],
                ['a', 'e', 'i', 'o', 'u', 'n', 'u'],
                $normK
            );
            $normK = str_replace(['_', ' '], '', $normK);
            if (isset($normalizedData[$normK])) {
                return $normalizedData[$normK];
            }
        }

        return $default;
    }

    private function mapRowData(array $row, array $headers): array
    {
        // 1. Crear mapa de index de cabeceras normalizadas
        $headerMap = [];
        foreach ($headers as $idx => $header) {
            $norm = strtolower(trim($header));
            $norm = str_replace(['á', 'é', 'í', 'ó', 'ú', 'ñ', 'ü'], ['a', 'e', 'i', 'o', 'u', 'n', 'u'], $norm);
            $norm = str_replace(['_', ' '], '', $norm);
            $headerMap[$norm] = $idx;
        }

        // Obtener indices clave
        $stockIdx = $headerMap['stock'] ?? $headerMap['stoc'] ?? $headerMap['cantidad'] ?? -1;
        $unitIdx = $headerMap['unidad'] ?? $headerMap['unidaddemedida'] ?? $headerMap['unidaddemedid'] ?? -1;
        $pCompraIdx = $headerMap['preciocompra'] ?? $headerMap['preciocompr'] ?? -1;
        $pVentaIdx = $headerMap['precioventa'] ?? $headerMap['preciovent'] ?? $headerMap['pvp'] ?? -1;

        // Comprobar si hay desfase/desplazamiento a la derecha:
        // Si el valor en la columna de stock es una cadena no numérica como "UND", "PCS", etc.
        $isShifted = false;
        if ($stockIdx !== -1 && isset($row[$stockIdx])) {
            $val = trim($row[$stockIdx]);
            if (!empty($val) && !is_numeric($val) && in_array(strtoupper($val), ['UND', 'PCS', 'NIU', 'GLG', 'BX', 'UNIDAD'])) {
                $isShifted = true;
            }
        }

        // Si está desfasado, reordenamos los valores de las columnas numéricas/unidad
        if ($isShifted && $stockIdx !== -1) {
            // En un desfasado, el stock real está a la derecha del stock_idx (stockIdx + 1)
            $actualStock = isset($row[$stockIdx + 1]) ? trim($row[$stockIdx + 1]) : '0';
            $actualUnit = isset($row[$stockIdx]) ? trim($row[$stockIdx]) : 'NIU';
            $actualCompra = ($unitIdx !== -1 && isset($row[$unitIdx])) ? trim($row[$unitIdx]) : '0';
            $actualVenta = ($pCompraIdx !== -1 && isset($row[$pCompraIdx])) ? trim($row[$pCompraIdx]) : '0';

            // Re-asignamos en el row para que el mapeo estándar funcione
            $row[$stockIdx] = $actualStock;
            if ($unitIdx !== -1) $row[$unitIdx] = $actualUnit;
            if ($pCompraIdx !== -1) $row[$pCompraIdx] = $actualCompra;
            if ($pVentaIdx !== -1) $row[$pVentaIdx] = $actualVenta;
        }

        // Mapeo normal
        $data = [];
        foreach ($headers as $i => $col) {
            $data[$col] = isset($row[$i]) ? trim($row[$i]) : '';
        }
        return $data;
    }
}
