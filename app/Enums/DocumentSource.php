<?php

namespace App\Enums;

enum DocumentSource: string
{
    case Email = 'email';
    case Upload = 'upload';
}
