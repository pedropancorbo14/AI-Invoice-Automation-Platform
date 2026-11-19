<?php

namespace App\Enums;

enum DocumentEventType: string
{
    case Created = 'created';
    case StatusChanged = 'status_changed';
}
