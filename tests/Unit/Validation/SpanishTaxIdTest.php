<?php

use App\Support\Validation\SpanishTaxId;

it('validates Spanish tax ids with their control character', function (string $id, bool $valid) {
    expect(SpanishTaxId::isValid($id))->toBe($valid);
})->with([
    'CIF S.L. (dígito)' => ['B12345674', true],
    'CIF S.L. dígito erróneo' => ['B12345675', false],
    'CIF S.L. con letra (no permitido)' => ['B1234567D', false],
    'CIF organismo (letra)' => ['Q2826000H', true],
    'CIF organismo con dígito (no permitido)' => ['Q28260008', false],
    'CIF asociación G (dígito o letra)' => ['G12345674', true],
    'CIF asociación G con letra' => ['G1234567D', true],
    'DNI' => ['12345678Z', true],
    'DNI letra errónea' => ['12345678A', false],
    'DNI con guion y minúscula' => ['12345678-z', true],
    'NIE X' => ['X1234567L', true],
    'NIE Y' => ['Y1234567X', true],
    'NIE letra errónea' => ['X1234567A', false],
    'NIF-IVA español' => ['ESB12345674', true],
    'NIF-IVA alemán' => ['DE123456789', true],
    'NIF-IVA francés' => ['FRXX999999999', true],
    'corto' => ['B1234567', false],
    'basura' => ['FACTURA', false],
    'vacío' => ['', false],
]);
