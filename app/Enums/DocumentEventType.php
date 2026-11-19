<?php

namespace App\Enums;

enum DocumentEventType: string
{
    case Created = 'created';
    case StatusChanged = 'status_changed';
    case Assigned = 'assigned';
    case Corrected = 'corrected';
    case FileReplaced = 'file_replaced';
}
