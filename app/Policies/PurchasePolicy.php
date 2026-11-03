<?php

namespace App\Policies;

use App\Models\User;

class PurchasePolicy extends ShieldPolicy
{
    protected string $key = 'purchase';

    // Una compra no se edita ni se borra: se anula (y el stock sale por el kardex)
    public function delete(User $user, $model): bool { return false; }
    public function deleteAny(User $user): bool { return false; }
}
