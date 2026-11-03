<?php

namespace App\Policies;

use App\Models\User;

class StockMovementPolicy extends ShieldPolicy
{
    protected string $key = 'stock::movement';

    // El kardex no se edita ni se borra: solo se consulta
    public function create(User $user): bool { return false; }
    public function update(User $user, $model): bool { return false; }
    public function delete(User $user, $model): bool { return false; }
    public function deleteAny(User $user): bool { return false; }
}
