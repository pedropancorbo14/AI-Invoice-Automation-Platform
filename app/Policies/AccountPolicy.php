<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\User;

class AccountPolicy
{
    public function view(User $user, Account $account): bool
    {
        return $user->belongsToAccount($account->id);
    }

    public function update(User $user, Account $account): bool
    {
        return $user->isAdmin() && $user->belongsToAccount($account->id);
    }
}
