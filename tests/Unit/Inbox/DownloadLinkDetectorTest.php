<?php

use App\Support\Inbox\DownloadLinkDetector;

it('finds invoice download links', function (string $text, string $html, array $expected) {
    expect((new DownloadLinkDetector)->detect($text, $html))->toBe($expected);
})->with([
    'botón HTML' => ['', '<a href="https://portal.test/x/1">Descargar factura</a>', ['https://portal.test/x/1']],
    'enlace a pdf' => ['', '<a href="https://cdn.test/f/2026.pdf">aquí</a>', ['https://cdn.test/f/2026.pdf']],
    'inglés' => ['', '<a href="https://app.test/i/9">View invoice</a>', ['https://app.test/i/9']],
    'texto con contexto' => ['Descargue su factura en https://area.test/doc/77.', '', ['https://area.test/doc/77']],
    'redes y baja' => ['', '<a href="https://facebook.com/x">FB</a><a href="https://x.test/unsubscribe">Baja</a>', []],
    'enlace normal' => ['Visita https://proveedor.test/blog', '', []],
    'mailto' => ['', '<a href="mailto:facturas@x.test">factura</a>', []],
    'sin cuerpo' => ['', '', []],
]);
