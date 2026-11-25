<?php

namespace App\Support\Verifactu;

/**
 * Lee los códigos QR de un documento (PDF o imagen). En los tests se cambia
 * por Tests\Support\FakeQrCodeReader.
 */
interface QrCodeReader
{
    /**
     * Texto de cada QR encontrado. Vacío si no hay ninguno o no se puede
     * leer: nunca lanza, porque el QR es una ayuda y no debe parar la
     * extracción.
     *
     * @return list<string>
     */
    public function read(string $contents, string $mimeType): array;
}
