<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;

/**
 * Segunda línea de defensa tras el global scope: aunque una consulta se
 * saltase el scope, un usuario nunca puede tocar documentos de otra cuenta.
 */
class DocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Document $document): bool
    {
        return $user->belongsToAccount($document->account_id);
    }

    public function create(User $user): bool
    {
        return true;
    }

    /** Revisar, corregir y aprobar: administradores y revisores. */
    public function update(User $user, Document $document): bool
    {
        return $user->belongsToAccount($document->account_id);
    }

    public function delete(User $user, Document $document): bool
    {
        return $user->isAdmin() && $user->belongsToAccount($document->account_id);
    }
}
