<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Política base que traduce cada acción al permiso de Filament Shield
 * (por ejemplo view_any_color, create_color). Las políticas de los modelos
 * nuevos solo definen $key.
 */
abstract class ShieldPolicy
{
    use HandlesAuthorization;

    /** Identificador de Shield, por ejemplo 'color' o 'size::curve'. */
    protected string $key;

    public function viewAny(User $user): bool { return $user->can("view_any_{$this->key}"); }
    public function view(User $user, $model): bool { return $user->can("view_{$this->key}"); }
    public function create(User $user): bool { return $user->can("create_{$this->key}"); }
    public function update(User $user, $model): bool { return $user->can("update_{$this->key}"); }
    public function delete(User $user, $model): bool { return $user->can("delete_{$this->key}"); }
    public function deleteAny(User $user): bool { return $user->can("delete_any_{$this->key}"); }
    public function forceDelete(User $user, $model): bool { return $user->can("force_delete_{$this->key}"); }
    public function forceDeleteAny(User $user): bool { return $user->can("force_delete_any_{$this->key}"); }
    public function restore(User $user, $model): bool { return $user->can("restore_{$this->key}"); }
    public function restoreAny(User $user): bool { return $user->can("restore_any_{$this->key}"); }
    public function replicate(User $user, $model): bool { return $user->can("replicate_{$this->key}"); }
    public function reorder(User $user): bool { return $user->can("reorder_{$this->key}"); }
}
