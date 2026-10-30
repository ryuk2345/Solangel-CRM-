<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Serie;
use Illuminate\Auth\Access\HandlesAuthorization;

class SeriePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('view_any_serie');
    }

    public function view(User $user, Serie $serie): bool
    {
        return $user->can('view_serie');
    }

    public function create(User $user): bool
    {
        return $user->can('create_serie');
    }

    public function update(User $user, Serie $serie): bool
    {
        return $user->can('update_serie');
    }

    public function delete(User $user, Serie $serie): bool
    {
        return $user->can('delete_serie');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any_serie');
    }

    public function forceDelete(User $user, Serie $serie): bool
    {
        return $user->can('force_delete_serie');
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->can('force_delete_any_serie');
    }

    public function restore(User $user, Serie $serie): bool
    {
        return $user->can('restore_serie');
    }

    public function restoreAny(User $user): bool
    {
        return $user->can('restore_any_serie');
    }

    public function replicate(User $user, Serie $serie): bool
    {
        return $user->can('replicate_serie');
    }

    public function reorder(User $user): bool
    {
        return $user->can('reorder_serie');
    }
}
