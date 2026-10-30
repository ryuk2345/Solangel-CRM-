<?php

namespace App\Policies;

use App\Models\User;
use App\Models\StockTransfer;
use Illuminate\Auth\Access\HandlesAuthorization;

class StockTransferPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('view_any_stock::transfer');
    }

    public function view(User $user, StockTransfer $stockTransfer): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        if (!$user->can('view_stock::transfer')) {
            return false;
        }

        return (int)$stockTransfer->origin_branch_id === (int)$user->branch_id
            || (int)$stockTransfer->destination_branch_id === (int)$user->branch_id;
    }

    public function create(User $user): bool
    {
        return $user->can('create_stock::transfer');
    }

    public function update(User $user, StockTransfer $stockTransfer): bool
    {
        // Solo traspasos en tránsito se pueden actualizar (recibir)
        if ($stockTransfer->status !== 'en_transito') {
            return false;
        }

        if ($user->hasRole('super_admin')) {
            return true;
        }

        if (!$user->can('update_stock::transfer')) {
            return false;
        }

        // Vendedor solo puede actualizar (recibir) si es la tienda de destino
        return (int)$stockTransfer->destination_branch_id === (int)$user->branch_id;
    }

    public function delete(User $user, StockTransfer $stockTransfer): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, StockTransfer $stockTransfer): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function replicate(User $user, StockTransfer $stockTransfer): bool
    {
        return false;
    }

    public function reorder(User $user): bool
    {
        return false;
    }
}
