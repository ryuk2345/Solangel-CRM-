<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Resources\ProductResource;
use App\Models\Inventory;
use App\Models\Warehouse;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = Auth::user();
        if ($user && !$user->hasRole('super_admin')) {
            // Un usuario que no es super_admin solo puede crear productos en su propia tienda
            $data['branches'] = [$user->branch_id ?? 1];
        }
        return $data;
    }

    protected function afterCreate(): void
    {
        $product = $this->record;
        $user = Auth::user();

        // Si no es super_admin, asegurar vinculación con la tienda del usuario
        if ($user && !$user->hasRole('super_admin')) {
            $userBranchId = $user->branch_id ?? 1;
            $product->branches()->syncWithoutDetaching([$userBranchId]);
        }

        // Regla: Después de vincular las tiendas, asegurar las filas en 0 de todas sus variantes en los almacenes de esas tiendas
        $branchIds = $product->branches()->pluck('branches.id');
        if ($branchIds->isNotEmpty()) {
            $warehouses = Warehouse::whereIn('branch_id', $branchIds)->where('is_active', true)->get();
            $variants = $product->variants;

            foreach ($warehouses as $wh) {
                foreach ($variants as $var) {
                    Inventory::firstOrCreate([
                        'warehouse_id' => $wh->id,
                        'product_variant_id' => $var->id,
                    ], [
                        'branch_id' => $wh->branch_id,
                        'stock' => 0,
                    ]);
                }
            }
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
