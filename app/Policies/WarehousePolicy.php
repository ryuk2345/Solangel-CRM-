<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Auth\Access\HandlesAuthorization;

class WarehousePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('view_any_warehouse');
    }

    public function view(User $user, Warehouse $warehouse): bool
    {
        return $user->can('view_warehouse');
    }

    public function create(User $user): bool
    {
        return $user->can('create_warehouse');
    }

    public function update(User $user, Warehouse $warehouse): bool
    {
        return $user->can('update_warehouse');
    }

    public function delete(User $user, Warehouse $warehouse): bool
    {
        return $user->can('delete_warehouse');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any_warehouse');
    }

    public function restore(User $user, Warehouse $warehouse): bool
    {
        return $user->can('restore_warehouse');
    }

    public function restoreAny(User $user): bool
    {
        return $user->can('restore_any_warehouse');
    }

    public function replicate(User $user, Warehouse $warehouse): bool
    {
        return $user->can('replicate_warehouse');
    }

    public function reorder(User $user): bool
    {
        return $user->can('reorder_warehouse');
    }
}
