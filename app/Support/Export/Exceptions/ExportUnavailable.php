<?php

namespace App\Support\Export\Exceptions;

use RuntimeException;

/**
 * El destino no responde (caído, límite de peticiones, red). Se reintenta.
 */
class ExportUnavailable extends RuntimeException {}
