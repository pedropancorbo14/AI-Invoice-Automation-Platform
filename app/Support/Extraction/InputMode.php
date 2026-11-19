<?php

namespace App\Support\Extraction;

/**
 * Qué se le manda al modelo.
 */
enum InputMode: string
{
    /** Texto extraído de un PDF con capa de texto: lo más barato. */
    case Text = 'text';

    /** El PDF entero (escaneos): el modelo lo lee como imágenes. */
    case Pdf = 'pdf';

    /** Una foto (JPG/PNG; HEIC se convierte antes). */
    case Image = 'image';
}
