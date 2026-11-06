<?php

namespace App\Policies;

use App\Models\User;

class CustomerPaymentPolicy extends ShieldPolicy
{
    protected string $key = 'customer::payment';

    public function update(User $user, $model): bool { return false; }
    public function delete(User $user, $model): bool { return false; }
    public function deleteAny(User $user): bool { return false; }
}
