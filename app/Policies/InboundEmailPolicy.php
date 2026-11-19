<?php

namespace App\Policies;

use App\Models\InboundEmail;
use App\Models\User;

class InboundEmailPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    /** Los correos sin cuenta (account_id nulo) no los ve ningún cliente. */
    public function view(User $user, InboundEmail $email): bool
    {
        return $email->account_id !== null && $user->belongsToAccount($email->account_id);
    }
}
