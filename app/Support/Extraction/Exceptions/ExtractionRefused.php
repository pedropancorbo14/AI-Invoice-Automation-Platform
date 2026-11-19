<?php

namespace App\Support\Extraction\Exceptions;

use RuntimeException;

/**
 * El modelo se ha negado a procesar el documento. Lo revisa una persona.
 */
class ExtractionRefused extends RuntimeException {}
