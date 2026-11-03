<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Resources\ProductResource;
use App\Models\Inventory;
use App\Models\Warehouse;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    public function mount(int | string $record): void
    {
        parent::mount($record);

        $user = Auth::user();
        if ($user && !$user->seesAllBranches()) {
            $branchIds = $this->record->branches->pluck('id');
            if ($branchIds->count() > 1) {
                abort(403, 'Este producto está compartido entre varias tiendas. Solo el administrador puede editarlo.');
            }
            if (!$branchIds->contains($user->branch_id ?? 1)) {
                abort(403, 'No tienes permiso para editar un producto que no pertenece a tu tienda.');
            }
        }
    }

    protected function beforeSave(): void
    {
        $user = Auth::user();
        if ($user && !$user->seesAllBranches()) {
            return;
        }

        // Si es super_admin y modificó las tiendas asignadas
        $newBranchIds = collect($this->data['branches'] ?? [])->map(fn($id) => (int)$id)->all();
        $currentBranchIds = $this->record->branches()->pluck('branches.id')->map(fn($id) => (int)$id)->all();
        $removedBranchIds = array_diff($currentBranchIds, $newBranchIds);

        $variantIds = $this->record->variants()->pluck('id');

        foreach ($removedBranchIds as $removedBranchId) {
            $branch = \App\Models\Branch::find($removedBranchId);
            $branchName = $branch?->name ?? "ID {$removedBranchId}";

            // 1. Validar que no tenga stock en esa tienda
            $stock = Inventory::where('branch_id', $removedBranchId)
                ->whereIn('product_variant_id', $variantIds)
                ->sum('stock');

            if ($stock > 0) {
                throw ValidationException::withMessages([
                    'branches' => "No se puede desasociar la tienda '{$branchName}' porque tiene {$stock} unidades en stock físico en sus almacenes. Ajusta el stock a 0 o realiza un traspaso antes de desasociarla.",
                ]);
            }

            // 2. Validar que no tenga traspasos en tránsito pendientes
            $inTransit = \App\Models\StockTransferItem::whereIn('product_variant_id', $variantIds)
                ->whereHas('stockTransfer', function ($q) use ($removedBranchId) {
                    $q->where('status', 'en_transito')
                      ->where(function ($sub) use ($removedBranchId) {
                          $sub->where('origin_branch_id', $removedBranchId)
                              ->orWhere('destination_branch_id', $removedBranchId);
                      });
                })->exists();

            if ($inTransit) {
                throw ValidationException::withMessages([
                    'branches' => "No se puede desasociar la tienda '{$branchName}' porque tiene traspasos de stock en tránsito pendientes para este producto.",
                ]);
            }
        }
    }

    protected function afterSave(): void
    {
        $product = $this->record;
        $branchIds = $product->branches()->pluck('branches.id');
        $variantIds = $product->variants()->pluck('id');

        // Para las tiendas vinculadas, asegurar que sus almacenes tengan filas en 0 para todas las variantes
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

        // Para las tiendas que ya NO están vinculadas, limpiar filas con stock 0 de este producto
        Inventory::whereNotIn('branch_id', $branchIds)
            ->whereIn('product_variant_id', $variantIds)
            ->where('stock', 0)
            ->delete();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->visible(fn () => ProductResource::userCanManage($this->record)),
        ];
    }

    protected function getRedirectUrl(): ?string
    {
        return null; // se queda en el modelo para seguir editando variantes
    }
}
