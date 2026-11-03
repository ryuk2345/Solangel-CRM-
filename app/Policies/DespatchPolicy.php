<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Despatch;
use Illuminate\Auth\Access\HandlesAuthorization;

class DespatchPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('view_any_despatch');
    }

    public function view(User $user, Despatch $despatch): bool
    {
        return $user->can('view_despatch');
    }

    public function create(User $user): bool
    {
        return $user->can('create_despatch');
    }

    public function update(User $user, Despatch $despatch): bool
    {
        return $user->can('update_despatch');
    }

    public function delete(User $user, Despatch $despatch): bool
    {
        return $user->can('delete_despatch');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any_despatch');
    }

    public function forceDelete(User $user, Despatch $despatch): bool
    {
        return $user->can('force_delete_despatch');
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->can('force_delete_any_despatch');
    }

    public function restore(User $user, Despatch $despatch): bool
    {
        return $user->can('restore_despatch');
    }

    public function restoreAny(User $user): bool
    {
        return $user->can('restore_any_despatch');
    }

    public function replicate(User $user, Despatch $despatch): bool
    {
        return $user->can('replicate_despatch');
    }

    public function reorder(User $user): bool
    {
        return $user->can('reorder_despatch');
    }
}
