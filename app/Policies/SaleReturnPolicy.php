<?php

namespace App\Policies;

use App\Models\User;

class SaleReturnPolicy extends ShieldPolicy
{
    protected string $key = 'sale::return';

    public function delete(User $user, $model): bool { return false; }
    public function deleteAny(User $user): bool { return false; }
}
