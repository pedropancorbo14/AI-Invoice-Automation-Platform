<?php

namespace App\Support\Extraction\Exceptions;

use RuntimeException;

/**
 * El documento no se puede mandar al modelo (formato que no sabemos
 * convertir, PDF dañado...). Lo revisa una persona.
 */
class UnsupportedInput extends RuntimeException {}
