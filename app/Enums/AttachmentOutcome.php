<?php

namespace App\Enums;

/**
 * Qué se hizo con cada adjunto de un correo (se guarda en el resumen).
 */
enum AttachmentOutcome: string
{
    case Stored = 'stored';
    case Duplicate = 'duplicate';
    case TooSmall = 'too_small';
    case Unsupported = 'unsupported';
    case TooLarge = 'too_large';
}
