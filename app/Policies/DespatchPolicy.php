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
        return true;
    }

    public function view(User $user, Despatch $despatch): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Despatch $despatch): bool
    {
        return true;
    }

    public function delete(User $user, Despatch $despatch): bool
    {
        return true;
    }

    public function deleteAny(User $user): bool
    {
        return true;
    }

    public function forceDelete(User $user, Despatch $despatch): bool
    {
        return true;
    }

    public function forceDeleteAny(User $user): bool
    {
        return true;
    }

    public function restore(User $user, Despatch $despatch): bool
    {
        return true;
    }

    public function restoreAny(User $user): bool
    {
        return true;
    }

    public function replicate(User $user, Despatch $despatch): bool
    {
        return true;
    }

    public function reorder(User $user): bool
    {
        return true;
    }
}
