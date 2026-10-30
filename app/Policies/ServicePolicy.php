<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Service;
use Illuminate\Auth\Access\HandlesAuthorization;

class ServicePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('view_any_service');
    }

    public function view(User $user, Service $service): bool
    {
        return $user->can('view_service');
    }

    public function create(User $user): bool
    {
        return $user->can('create_service');
    }

    public function update(User $user, Service $service): bool
    {
        return $user->can('update_service');
    }

    public function delete(User $user, Service $service): bool
    {
        return $user->can('delete_service');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any_service');
    }

    public function forceDelete(User $user, Service $service): bool
    {
        return $user->can('force_delete_service');
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->can('force_delete_any_service');
    }

    public function restore(User $user, Service $service): bool
    {
        return $user->can('restore_service');
    }

    public function restoreAny(User $user): bool
    {
        return $user->can('restore_any_service');
    }

    public function replicate(User $user, Service $service): bool
    {
        return $user->can('replicate_service');
    }

    public function reorder(User $user): bool
    {
        return $user->can('reorder_service');
    }
}
