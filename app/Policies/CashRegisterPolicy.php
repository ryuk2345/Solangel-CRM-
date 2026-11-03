<?php

namespace App\Policies;

use App\Models\User;
use App\Models\CashRegister;
use Illuminate\Auth\Access\HandlesAuthorization;

class CashRegisterPolicy
{
    use HandlesAuthorization;

    /**
     * Solo los roles con permiso de caja entran a la lista (vendedor, administrador, contador).
     * En el resource se filtra para que cada vendedor vea solo la suya.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('view_any_cash::register');
    }

    /**
     * Un usuario puede ver una caja si es la suya o si tiene permiso de admin.
     */
    public function view(User $user, CashRegister $cashRegister): bool
    {
        return $cashRegister->user_id === $user->id || $user->can('view_cash::register');
    }

    public function create(User $user): bool
    {
        return $user->can('create_cash::register'); // vendedor y administrador abren su propia caja
    }

    public function update(User $user, CashRegister $cashRegister): bool
    {
        // Puede actualizar (cerrar) su propia caja, o si tiene permiso de admin
        return $cashRegister->user_id === $user->id || $user->can('update_cash::register');
    }

    public function delete(User $user, CashRegister $cashRegister): bool
    {
        return $user->can('delete_cash::register');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any_cash::register');
    }

    public function forceDelete(User $user, CashRegister $cashRegister): bool
    {
        return $user->can('force_delete_cash::register');
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->can('force_delete_any_cash::register');
    }

    public function restore(User $user, CashRegister $cashRegister): bool
    {
        return $user->can('restore_cash::register');
    }

    public function restoreAny(User $user): bool
    {
        return $user->can('restore_any_cash::register');
    }

    public function replicate(User $user, CashRegister $cashRegister): bool
    {
        return $user->can('replicate_cash::register');
    }

    public function reorder(User $user): bool
    {
        return $user->can('reorder_cash::register');
    }
}

