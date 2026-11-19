<?php

namespace App\Support\Export\Exceptions;

use RuntimeException;

/**
 * Este destino no aplica a este documento (p. ej. una factura emitida no se
 * exporta como gasto). No es un error.
 */
class ExportSkipped extends RuntimeException {}
