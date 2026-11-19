<?php

namespace App\Enums;

enum UserRole: string
{
    /** Gestiona la cuenta: clientes (companies), usuarios y configuración. */
    case Admin = 'admin';

    /** Revisa y corrige documentos, pero no gestiona la cuenta. */
    case Reviewer = 'reviewer';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrador',
            self::Reviewer => 'Revisor',
        };
    }
}
