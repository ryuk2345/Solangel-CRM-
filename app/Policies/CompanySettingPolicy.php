<?php

namespace App\Policies;

use App\Models\User;
use App\Models\CompanySetting;
use Illuminate\Auth\Access\HandlesAuthorization;

class CompanySettingPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('view_any_company::setting');
    }

    public function view(User $user, CompanySetting $companySetting): bool
    {
        return $user->can('view_company::setting');
    }

    public function create(User $user): bool
    {
        return $user->can('create_company::setting');
    }

    public function update(User $user, CompanySetting $companySetting): bool
    {
        return $user->can('update_company::setting');
    }

    public function delete(User $user, CompanySetting $companySetting): bool
    {
        return $user->can('delete_company::setting');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any_company::setting');
    }

    public function forceDelete(User $user, CompanySetting $companySetting): bool
    {
        return $user->can('force_delete_company::setting');
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->can('force_delete_any_company::setting');
    }

    public function restore(User $user, CompanySetting $companySetting): bool
    {
        return $user->can('restore_company::setting');
    }

    public function restoreAny(User $user): bool
    {
        return $user->can('restore_any_company::setting');
    }

    public function replicate(User $user, CompanySetting $companySetting): bool
    {
        return $user->can('replicate_company::setting');
    }

    public function reorder(User $user): bool
    {
        return $user->can('reorder_company::setting');
    }
}
