<?php

namespace App\Policies;

use App\Models\User;
use App\Models\AuditSale;
use Illuminate\Auth\Access\HandlesAuthorization;

class AuditSalePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('view_any_audit::sale');
    }

    public function view(User $user, AuditSale $auditSale): bool
    {
        return $user->can('view_audit::sale');
    }

    public function create(User $user): bool
    {
        return $user->can('create_audit::sale');
    }

    public function update(User $user, AuditSale $auditSale): bool
    {
        return $user->can('update_audit::sale');
    }

    public function delete(User $user, AuditSale $auditSale): bool
    {
        return $user->can('delete_audit::sale');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any_audit::sale');
    }

    public function forceDelete(User $user, AuditSale $auditSale): bool
    {
        return $user->can('force_delete_audit::sale');
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->can('force_delete_any_audit::sale');
    }

    public function restore(User $user, AuditSale $auditSale): bool
    {
        return $user->can('restore_audit::sale');
    }

    public function restoreAny(User $user): bool
    {
        return $user->can('restore_any_audit::sale');
    }

    public function replicate(User $user, AuditSale $auditSale): bool
    {
        return $user->can('replicate_audit::sale');
    }

    public function reorder(User $user): bool
    {
        return $user->can('reorder_audit::sale');
    }
}
