<?php

namespace App\Support\Extraction\Exceptions;

use RuntimeException;

/**
 * Error que no se arregla reintentando (petición inválida, clave incorrecta,
 * respuesta que no cumple el esquema...). El documento pasa a FAILED.
 */
class ExtractionFailed extends RuntimeException {}
