<?php

namespace App\Support\Extraction;

enum Confidence: string
{
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';
}
