<?php

namespace App\Support\Export\Exceptions;

use RuntimeException;

/**
 * Error que no se arregla reintentando (clave caducada, datos que el destino
 * rechaza...). El documento va a revisión con este mensaje.
 */
class ExportFailed extends RuntimeException {}
