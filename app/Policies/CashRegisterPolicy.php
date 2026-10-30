<?php

namespace App\Policies;

use App\Models\User;
use App\Models\CashRegister;
use Illuminate\Auth\Access\HandlesAuthorization;

class CashRegisterPolicy
{
    use HandlesAuthorization;

    /**
     * Cualquier usuario autenticado puede ver la lista de cajas.
     * En el resource filtramos para que cada vendedor solo vea la suya.
     */
    public function viewAny(User $user): bool
    {
        return true; // Todos los usuarios autenticados pueden entrar a la página de cajas
    }

    /**
     * Un usuario puede ver una caja si es la suya o si tiene permiso de admin.
     */
    public function view(User $user, CashRegister $cashRegister): bool
    {
        return $cashRegister->user_id === $user->id || $user->can('view_cash_register');
    }

    public function create(User $user): bool
    {
        return true; // Todos pueden abrir su propia caja
    }

    public function update(User $user, CashRegister $cashRegister): bool
    {
        // Puede actualizar (cerrar) su propia caja, o si tiene permiso de admin
        return $cashRegister->user_id === $user->id || $user->can('update_cash_register');
    }

    public function delete(User $user, CashRegister $cashRegister): bool
    {
        return $user->can('delete_cash_register');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any_cash_register');
    }

    public function forceDelete(User $user, CashRegister $cashRegister): bool
    {
        return $user->can('force_delete_cash_register');
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->can('force_delete_any_cash_register');
    }

    public function restore(User $user, CashRegister $cashRegister): bool
    {
        return $user->can('restore_cash_register');
    }

    public function restoreAny(User $user): bool
    {
        return $user->can('restore_any_cash_register');
    }

    public function replicate(User $user, CashRegister $cashRegister): bool
    {
        return $user->can('replicate_cash_register');
    }

    public function reorder(User $user): bool
    {
        return $user->can('reorder_cash_register');
    }
}

