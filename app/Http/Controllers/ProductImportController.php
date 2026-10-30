<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Product;
use App\Models\Category;
use App\Models\Brand;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductImportController extends Controller
{
    public function page()
    {
        $user = auth()->user();
        $query = \App\Models\Warehouse::with('branch')->where('is_active', true);
        if ($user && !$user->hasRole('super_admin')) {
            $query->where('branch_id', $user->branch_id ?? 1);
        }
        $warehouses = $query->get();
        return view('import.upload', compact('warehouses'));
    }

    public function import(Request $request)
    {
        // Optimizar memoria y tiempo de ejecución para subidas masivas (+30,000 items)
        set_time_limit(0);
        ini_set('memory_limit', '1024M');
        DB::disableQueryLog();

        $request->validate([
            'csv_file'     => 'required|file|max:30720',
            'warehouse_id' => 'nullable|exists:warehouses,id',
        ], [
            'csv_file.required' => 'Debes seleccionar un archivo CSV.',
            'csv_file.max'      => 'El archivo no puede superar 30MB.',
        ]);

        $file = $request->file('csv_file');
        $extension = strtolower($file->getClientOriginalExtension());
        if (!in_array($extension, ['csv', 'txt'])) {
            return redirect()->route('import.products.page')->withErrors([
                'csv_file' => 'El archivo debe ser un archivo CSV o TXT (extensión .csv o .txt).'
            ]);
        }

        $path    = $file->getRealPath();

        // Leer y normalizar el contenido
        $content = file_get_contents($path);

        // Verificar si es un archivo Excel real (.xlsx o .xls) renombrado a .csv
        if (str_starts_with($content, "PK\x03\x04") || str_starts_with($content, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1")) {
            return redirect()->route('import.products.page')->withErrors([
                'csv_file' => '⚠️ El archivo subido es un archivo Excel (.xlsx/.xls) renombrado a .csv. Por favor, abre el archivo en Excel, haz clic en "Guardar como..." y elige el formato "CSV (delimitado por comas) (*.csv)" antes de subirlo.'
            ]);
        }

        $content = ltrim($content, "\xEF\xBB\xBF");          // Eliminar BOM UTF-8
        $content = str_replace(["\r\n", "\r"], "\n", $content); // Normalizar saltos de línea

        // Detectar separador: coma o punto y coma (Excel en español usa ;)
        $firstLine = strtok($content, "\n");
        $sep = (substr_count($firstLine, ';') >= substr_count($firstLine, ',')) ? ';' : ',';

        // LOGGING DEBUG
        $debugInfo = "SEPARATOR: '{$sep}'\n";
        $debugInfo .= "FIRST LINE: {$firstLine}\n";
        $debugInfo .= "FIRST 1000 CHARS OF CONTENT:\n" . substr($content, 0, 1000) . "\n";
        file_put_contents(public_path('import_debug.txt'), $debugInfo);

        // Guardar contenido normalizado en archivo temporal
        $tmpPath = tempnam(sys_get_temp_dir(), 'magui_csv_');
        file_put_contents($tmpPath, $content);

        // Asegurar que existe la sucursal por defecto con ID 1 para el inventario
        if (!DB::table('branches')->where('id', 1)->exists()) {
            DB::table('branches')->insert([
                'id' => 1,
                'name' => 'Principal',
                'address' => 'Dirección Principal',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Pre-cargar categorías y marcas existentes en memoria para evitar miles de queries redundantes
        $categoriesCache = [];
        foreach (Category::all() as $cat) {
            $categoriesCache[Str::slug($cat->name)] = $cat->id;
        }

        $brandsCache = [];
        foreach (Brand::all() as $brand) {
            $brandsCache[Str::slug($brand->name)] = $brand->id;
        }

        $user = auth()->user();
        $userBranchId = ($user && !$user->hasRole('super_admin')) ? ($user->branch_id ?? 1) : null;

        $branches        = \App\Models\Branch::all();
        $allWarehouses   = \App\Models\Warehouse::where('is_active', true)->get();
        $reqWarehouseId  = (int) $request->input('warehouse_id');
        $targetWarehouse = $allWarehouses->firstWhere('id', $reqWarehouseId);

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

        $handle  = fopen($tmpPath, 'r');
        $headers = null;
        $imported = 0;
        $skipped  = 0;
        $errors   = [];
        $row_num  = 0;
        $total_rows = 0;

        $batchSize = 250;
        $currentBatch = [];

        // Función auxiliar interna para procesar una sola fila de datos
        $processRow = function($data, $row_index) use (&$categoriesCache, &$brandsCache, $branches, $allWarehouses, $targetWarehouseId, $targetBranchId) {
            $name = $this->getValueByKeys($data, ['DESCRIPCION', 'descripcion', 'name', 'nombre']);
            if (empty($name)) {
                return ['status' => 'skipped', 'message' => 'Nombre del producto vacío.'];
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

            // Categoría auto-crear optimizado en memoria
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

            // Marca auto-crear optimizado en memoria
            $brandId   = null;
            $brandName = trim($this->getValueByKeys($data, ['Marca', 'marca', 'brand']));
            if (!empty($brandName)) {
                $brandSlug = Str::slug($brandName);
                if (!isset($brandsCache[$brandSlug])) {
                    $brand = Brand::create(['name' => $brandName]);
                    $brandsCache[$brandSlug] = $brand->id;
                }
                $brandId = $brandsCache[$brandSlug];
            }

            // Precio compra
            $purchasePriceRaw = $this->getValueByKeys($data, ['Precio Compra', 'precio_compra', 'PRECIO COMPRA', 'precio_compr', 'preciocompr'], '0');
            $purchasePriceClean = str_replace(['S/', '$', ' ', 'S/.', 's/', 's/.'], '', $purchasePriceRaw);
            if (str_contains($purchasePriceClean, ',') && str_contains($purchasePriceClean, '.')) {
                $purchasePriceClean = str_replace(',', '', $purchasePriceClean);
            } elseif (str_contains($purchasePriceClean, ',')) {
                $purchasePriceClean = str_replace(',', '.', $purchasePriceClean);
            }
            $purchasePrice = (float) $purchasePriceClean;

            // Unidad
            $unit = $this->getValueByKeys($data, ['Unidad', 'unidad', 'unidad_de_medida', 'unidad_de_medid'], 'NIU');

            // Descripción / fabricante
            $desc = $this->getValueByKeys($data, ['Fabricante', 'fabricante', 'fabricant', 'description']);

            // SKU y OEM
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
                    'unit_code'      => !empty($unit) ? $unit : 'NIU',
                    'description'    => $desc,
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
                        ['warehouse_id' => $targetWarehouseId, 'product_variant_id' => $variant->id],
                        ['branch_id' => $targetBranchId, 'stock' => $stock, 'updated_at' => now(), 'created_at' => now()]
                    );

                    // Asegurar 0 en otros almacenes de la MISMA tienda
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

                return ['status' => 'success', 'name' => $name];
            }

            // Si llegamos aquí, el producto o variante existe en el sistema
            if (!$product && $variant) {
                $product = Product::find($variant->product_id);
            }
            if (!$product) {
                return ['status' => 'skipped', 'message' => "Error de integridad: variante sin producto padre."];
            }

            $productBranchIds = DB::table('branch_product')
                ->where('product_id', $product->id)
                ->pluck('branch_id')
                ->toArray();

            $sku = !empty($oemCode) ? $oemCode : ($variant ? $variant->sku : ('REP-' . str_pad($product->id, 5, '0', STR_PAD_LEFT)));
            $compat = $this->getValueByKeys($data, ['Compatibilidad', 'compatibilidad', 'compatibility'], 'Único');

            // CASO 4: SKU existe pero NO está habilitado en la tienda de destino -> Omitir y reportar
            if (!in_array($targetBranchId, $productBranchIds)) {
                $branchName = $branches->firstWhere('id', $targetBranchId)?->name ?? "Tienda #{$targetBranchId}";
                return [
                    'status'  => 'skipped',
                    'message' => "SKU '{$sku}' ({$name}) existe en el sistema pero no está habilitado en la tienda {$branchName}. Omitido.",
                ];
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
                    'unit_code'      => !empty($unit) ? $unit : 'NIU',
                    'description'    => $desc,
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

            // Actualizar stock en almacén de destino
            if (!$product->is_service) {
                $stockRaw = $this->getValueByKeys($data, ['Stock', 'stock', 'stoc', 'cantidad']);
                $stock = (int) $stockRaw;

                DB::table('inventories')->updateOrInsert(
                    ['warehouse_id' => $targetWarehouseId, 'product_variant_id' => $variant->id],
                    ['branch_id' => $targetBranchId, 'stock' => $stock, 'updated_at' => now(), 'created_at' => now()]
                );

                // Asegurar 0 en otros almacenes de la MISMA tienda
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

            if ($isShared) {
                return [
                    'status' => 'success',
                    'name'   => $name,
                    'notice' => "SKU '{$sku}' ({$name}): Producto compartido con otras tiendas. Se actualizó solo el stock en este almacén (datos maestros intactos)."
                ];
            }

            return ['status' => 'success', 'name' => $name];
        };

        // Procesar lotes
        $executeBatch = function($batch) use ($processRow, &$imported, &$skipped, &$errors) {
            try {
                DB::beginTransaction();
                foreach ($batch as $item) {
                    $res = $processRow($item['data'], $item['row_num']);
                    if ($res['status'] === 'skipped') {
                        $skipped++;
                        if (isset($res['message'])) {
                            $errors[] = "Fila {$item['row_num']}: {$res['message']}";
                        }
                    } else {
                        $imported++;
                        if (isset($res['notice'])) {
                            $errors[] = "ℹ️ Fila {$item['row_num']}: {$res['notice']}";
                        }
                    }
                }
                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                // Si falla el lote completo, procesamos cada fila una por una para registrar el error específico
                foreach ($batch as $item) {
                    try {
                        DB::beginTransaction();
                        $res = $processRow($item['data'], $item['row_num']);
                        if ($res['status'] === 'skipped') {
                            $skipped++;
                            if (isset($res['message'])) {
                                $errors[] = "Fila {$item['row_num']}: {$res['message']}";
                            }
                        } else {
                            $imported++;
                            if (isset($res['notice'])) {
                                $errors[] = "ℹ️ Fila {$item['row_num']}: {$res['notice']}";
                            }
                        }
                        DB::commit();
                    } catch (\Exception $subEx) {
                        DB::rollBack();
                        $name = $item['data']['DESCRIPCION'] ?? $item['data']['name'] ?? $item['data']['NOMBRE'] ?? 'Desconocido';
                        $errors[] = "Fila {$item['row_num']} ($name): " . $subEx->getMessage();
                        $skipped++;
                    }
                }
            }
        };

        // Desactivar eventos de Eloquent para evitar sobrecarga de base de datos
        \Illuminate\Database\Eloquent\Model::withoutEvents(function () use ($handle, $sep, &$row_num, &$total_rows, &$currentBatch, $batchSize, $executeBatch, &$headers) {
            while (($row = fgetcsv($handle, 4096, $sep)) !== false) {
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

                if ($headers === null) {
                    $headers = array_map('trim', $row);
                    continue;
                }

                if (empty(array_filter($row))) continue;

                $total_rows++;

                $data = $this->mapRowData($row, $headers);

                $currentBatch[] = [
                    'row_num' => $row_num,
                    'data' => $data
                ];

                if (count($currentBatch) >= $batchSize) {
                    $executeBatch($currentBatch);
                    $currentBatch = [];
                    gc_collect_cycles();
                }
            }

            // Procesar cualquier remanente del último lote
            if (count($currentBatch) > 0) {
                $executeBatch($currentBatch);
                gc_collect_cycles();
            }
        });

        fclose($handle);
        @unlink($tmpPath);

        if ($total_rows === 0) {
            $errors[] = '⚠️ El archivo CSV no contiene filas de datos. Solo tiene encabezados. Descarga el archivo de ejemplo y agrega tus productos.';
        }

        return redirect()->route('import.products.page')->with('results', [
            'imported'   => $imported,
            'skipped'    => $skipped,
            'total_rows' => $total_rows,
            'errors'     => $errors,
        ]);
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
