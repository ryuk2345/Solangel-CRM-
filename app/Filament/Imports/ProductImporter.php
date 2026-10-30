<?php

namespace App\Filament\Imports;

use App\Models\Product;
use App\Models\Category;
use App\Models\Brand;
use App\Models\ProductVariant;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Illuminate\Support\Facades\DB;
use Filament\Actions\Imports\Models\Import;

class ProductImporter extends Importer
{
    protected static ?string $model = Product::class;

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('name')
                ->label('DESCRIPCION')
                ->requiredMapping()
                ->rules(['required', 'string', 'max:255']),

            ImportColumn::make('category_id')
                ->label('Categoria'),

            ImportColumn::make('brand_id')
                ->label('Marca'),

            ImportColumn::make('oem_code')
                ->label('OEM'),

            ImportColumn::make('manufacturer')
                ->label('Fabricante'),

            ImportColumn::make('location')
                ->label('Ubicacion'),

            ImportColumn::make('compatibility')
                ->label('Compatibilidad'),

            ImportColumn::make('sale_price')
                ->label('Precio Venta')
                ->numeric()
                ->requiredMapping()
                ->rules(['required', 'numeric', 'min:0']),

            ImportColumn::make('purchase_price')
                ->label('Precio Compra')
                ->numeric()
                ->rules(['nullable', 'numeric', 'min:0']),

            ImportColumn::make('unit_code')
                ->label('Unidad'),

            ImportColumn::make('stock')
                ->label('Stock')
                ->numeric()
                ->rules(['nullable', 'integer', 'min:0']),
        ];
    }

    protected bool $isSharedProduct = false;

    public static function getOptionsFormComponents(): array
    {
        return [
            \Filament\Forms\Components\Select::make('warehouse_id')
                ->label('Almacén de Destino')
                ->options(function () {
                    $user = auth()->user();
                    $query = \App\Models\Warehouse::with('branch')->where('is_active', true);
                    if ($user && !$user->hasRole('super_admin')) {
                        $query->where('branch_id', $user->branch_id ?? 1);
                    }
                    return $query->get()->mapWithKeys(fn($w) => [
                        $w->id => ($w->branch?->name ?? 'Tienda') . ' - ' . $w->name . ($w->is_default ? ' (Por Defecto)' : '')
                    ]);
                })
                ->default(function () {
                    $user = auth()->user();
                    $query = \App\Models\Warehouse::where('is_active', true);
                    if ($user && !$user->hasRole('super_admin')) {
                        $query->where('branch_id', $user->branch_id ?? 1);
                    }
                    return $query->where('is_default', true)->value('id') ?? $query->value('id') ?? 1;
                })
                ->required(),
        ];
    }

    protected function getTargetWarehouse(): ?\App\Models\Warehouse
    {
        $user = auth()->user();
        $userBranchId = ($user && !$user->hasRole('super_admin')) ? ($user->branch_id ?? 1) : null;
        $whId = $this->options['warehouse_id'] ?? null;

        $warehouse = null;
        if ($whId) {
            $warehouse = \App\Models\Warehouse::find($whId);
        }

        if ($userBranchId) {
            if (!$warehouse || $warehouse->branch_id != $userBranchId) {
                $warehouse = \App\Models\Warehouse::where('branch_id', $userBranchId)->where('is_default', true)->first()
                    ?? \App\Models\Warehouse::where('branch_id', $userBranchId)->first();
            }
        }

        if (!$warehouse) {
            $warehouse = \App\Models\Warehouse::where('branch_id', 1)->where('is_default', true)->first()
                ?? \App\Models\Warehouse::first();
        }

        return $warehouse;
    }

    public function resolveRecord(): ?Product
    {
        // Si no hay nombre, omitir la fila
        if (empty($this->data['name'])) {
            return null;
        }

        $targetWarehouse = $this->getTargetWarehouse();
        $targetBranchId = $targetWarehouse ? $targetWarehouse->branch_id : 1;

        $oemCode = !empty($this->data['oem_code']) ? trim($this->data['oem_code']) : null;
        $record = null;

        if ($oemCode) {
            $variant = ProductVariant::where('sku', $oemCode)->first();
            if ($variant) {
                $record = Product::find($variant->product_id);
            } else {
                $record = Product::where('oem_code', $oemCode)->first();
            }
        } else {
            $record = Product::where('name', $this->data['name'])
                ->where(function($q) {
                    $q->whereNull('oem_code')->orWhere('oem_code', '');
                })->first();
        }

        if ($record) {
            $productBranchIds = DB::table('branch_product')
                ->where('product_id', $record->id)
                ->pluck('branch_id')
                ->toArray();

            // CASO 4: SKU existente no habilitado en la tienda de destino -> Omitir fila
            if (!in_array($targetBranchId, $productBranchIds)) {
                return null;
            }

            // CASO 3: SKU compartido entre varias tiendas -> Conservar datos maestros intactos
            if (count($productBranchIds) > 1) {
                $this->isSharedProduct = true;
                return $record;
            }

            $this->isSharedProduct = false;
        }

        // --- AUTO-CREAR CATEGORÍA ---
        if (!empty($this->data['category_id'])) {
            $categoryName = trim($this->data['category_id']);
            $category = Category::firstOrCreate(
                ['name' => $categoryName],
                ['slug' => str($categoryName)->slug()]
            );
            $this->data['category_id'] = $category->id;
        } else {
            $this->data['category_id'] = null;
        }

        // --- AUTO-CREAR MARCA ---
        if (!empty($this->data['brand_id'])) {
            $brandName = trim($this->data['brand_id']);
            $brand = Brand::firstOrCreate(['name' => $brandName]);
            $this->data['brand_id'] = $brand->id;
        } else {
            $this->data['brand_id'] = null;
        }

        // --- Unidad por defecto ---
        if (empty($this->data['unit_code'])) {
            $this->data['unit_code'] = 'NIU';
        }

        return $record ?? new Product();
    }

    protected function beforeSave(): void
    {
        if ($this->isSharedProduct && $this->record->exists) {
            // Descartar cambios a datos maestros para productos compartidos
            $this->record->refresh();
        }
    }

    protected function afterSave(): void
    {
        $product = $this->record;
        $targetWarehouse = $this->getTargetWarehouse();
        $targetWarehouseId = $targetWarehouse ? $targetWarehouse->id : 1;
        $targetBranchId = $targetWarehouse ? $targetWarehouse->branch_id : 1;

        // Asegurar vinculación con la tienda de destino
        if (!$product->branches()->where('branch_id', $targetBranchId)->exists()) {
            $product->branches()->syncWithoutDetaching([$targetBranchId]);
        }

        // SKU: usa OEM si existe, si no genera uno automático
        $sku = !empty($this->data['oem_code'])
            ? trim($this->data['oem_code'])
            : 'PROD-' . str_pad($product->id, 5, '0', STR_PAD_LEFT);

        // Compatibilidad como talla, Marca como color/referencia
        $size  = !empty($this->data['compatibility']) ? $this->data['compatibility'] : 'Único';
        $color = !empty($this->data['brand_id']) ? ($this->data['oem_code'] ?? 'Estándar') : 'Estándar';

        if ($this->isSharedProduct) {
            // CASO 3: No modificar datos de la variante
            $variant = ProductVariant::firstOrCreate(
                [
                    'product_id' => $product->id,
                    'sku'        => $sku,
                ],
                [
                    'size'           => $size,
                    'color_name'     => $color,
                    'sale_price'     => $product->sale_price ?? 0,
                    'purchase_price' => $product->purchase_price ?? 0,
                ]
            );
        } else {
            // CASO 1 y 2: Crear o actualizar variante del producto
            $variant = ProductVariant::updateOrCreate(
                [
                    'product_id' => $product->id,
                    'sku'        => $sku,
                ],
                [
                    'size'           => $size,
                    'color_name'     => $color,
                    'sale_price'     => $product->sale_price ?? 0,
                    'purchase_price' => $product->purchase_price ?? 0,
                ]
            );
        }

        // Actualizar stock en inventario
        $stock = (int) ($this->data['stock'] ?? 0);
        if ($stock >= 0 && !$product->is_service) {
            // 1. Asignar stock al almacén de destino
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

            // 2. Asegurar fila en 0 solo en otros almacenes de la MISMA tienda
            $otherWarehouses = \App\Models\Warehouse::where('branch_id', $targetBranchId)
                ->where('id', '!=', $targetWarehouseId)
                ->where('is_active', true)
                ->get();

            foreach ($otherWarehouses as $w) {
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
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Importación completada: ' . number_format($import->successful_rows) . ' ' .
            str('producto')->plural($import->successful_rows) . ' importados correctamente.';

        if ($failedRowsCount = $import->getFailedRowsCount()) {
            $body .= ' ' . number_format($failedRowsCount) . ' ' .
                str('fila')->plural($failedRowsCount) . ' con errores u omitidas.';
        }

        return $body;
    }
}
