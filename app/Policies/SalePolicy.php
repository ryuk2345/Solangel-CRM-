<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Sale;
use Illuminate\Auth\Access\HandlesAuthorization;

class SalePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('view_any_sale');
    }

    public function view(User $user, Sale $sale): bool
    {
        if (!$user->can('view_sale')) {
            return false;
        }

        if (!$user->hasRole('super_admin') && (int)$user->branch_id !== (int)$sale->branch_id) {
            return false;
        }

        return true;
    }

    public function create(User $user): bool
    {
        return $user->can('create_sale');
    }

    public function update(User $user, Sale $sale): bool
    {
        if (!$user->can('update_sale')) {
            return false;
        }

        if (!$user->hasRole('super_admin') && (int)$user->branch_id !== (int)$sale->branch_id) {
            return false;
        }

        return true;
    }

    public function delete(User $user, Sale $sale): bool
    {
        return $user->can('delete_sale');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any_sale');
    }

    public function forceDelete(User $user, Sale $sale): bool
    {
        return $user->can('force_delete_sale');
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->can('force_delete_any_sale');
    }

    public function restore(User $user, Sale $sale): bool
    {
        return $user->can('restore_sale');
    }

    public function restoreAny(User $user): bool
    {
        return $user->can('restore_any_sale');
    }

    public function replicate(User $user, Sale $sale): bool
    {
        return $user->can('replicate_sale');
    }

    public function reorder(User $user): bool
    {
        return $user->can('reorder_sale');
    }
}
