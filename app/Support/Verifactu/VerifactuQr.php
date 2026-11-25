<?php

namespace App\Support\Verifactu;

use App\Support\Extraction\TaxId;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Lo que dice el código QR de una factura VERI*FACTU (Orden HAC/1177/2024):
 * una URL de cotejo de la AEAT con el NIF del emisor, el número de serie, la
 * fecha de expedición y el importe total. Por ejemplo:
 *
 *   https://www2.agenciatributaria.gob.es/wlpl/TIKE-CONT/ValidarQR?nif=89890001K&numserie=12345678-G33&fecha=01-09-2024&importe=241.4
 *
 * El QR lo genera el programa de facturación del emisor a partir de sus
 * propios datos, así que es una fuente fiable para contrastar lo que lee la IA.
 */
final readonly class VerifactuQr
{
    /** Hosts de la AEAT: producción y preproducción (pruebas). */
    private const HOSTS = [
        'www2.agenciatributaria.gob.es' => false,
        'prewww2.aeat.es' => true,
    ];

    /** Rutas de cotejo: sistemas VERI*FACTU y "No VERI*FACTU". */
    private const PATHS = [
        '/wlpl/TIKE-CONT/ValidarQR' => true,
        '/wlpl/TIKE-CONT/ValidarQRNoVerifactu' => false,
    ];

    public function __construct(
        public string $taxId,
        public string $number,
        /** Y-m-d */
        public string $date,
        /** Texto con 2 decimales, como los importes extraídos. */
        public string $total,
        /** Emitida por un sistema VERI*FACTU (envía cada registro a la AEAT). */
        public bool $verifactu,
        /** URL del entorno de pruebas de la AEAT: una factura real no la lleva. */
        public bool $testEnvironment,
    ) {}

    /**
     * Interpreta el texto de un QR. Null si no es un QR de factura de la AEAT
     * o le falta algún dato.
     */
    public static function parse(string $text): ?self
    {
        $url = parse_url(trim($text));

        if (! is_array($url) || ! isset($url['host'], $url['path'], $url['query'])) {
            return null;
        }

        $host = strtolower($url['host']);
        $path = rtrim($url['path'], '/');

        if (! array_key_exists($host, self::HOSTS) || ! array_key_exists($path, self::PATHS)) {
            return null;
        }

        parse_str($url['query'], $params);
        // Los nombres de los parámetros pueden venir en cualquier caja.
        $params = array_change_key_case($params);

        $taxId = TaxId::normalize(self::param($params, 'nif'));
        $number = trim((string) self::param($params, 'numserie'));
        $date = self::date((string) self::param($params, 'fecha'));
        $total = self::amount((string) self::param($params, 'importe'));

        if ($taxId === null || $number === '' || $date === null || $total === null) {
            return null;
        }

        return new self($taxId, $number, $date, $total, self::PATHS[$path], self::HOSTS[$host]);
    }

    /**
     * El primer QR de factura de la AEAT entre varios textos leídos.
     *
     * @param  list<string>  $texts
     */
    public static function first(array $texts): ?self
    {
        foreach ($texts as $text) {
            if (($qr = self::parse($text)) !== null) {
                return $qr;
            }
        }

        return null;
    }

    /**
     * Compara el QR con los datos extraídos, campo a campo (con los nombres
     * de campo de la confianza): true si coincide, false si no, null si la
     * IA no leyó ese dato.
     *
     * @param  array<string, mixed>  $data
     * @return array{supplier_tax_id: bool|null, invoice_number: bool|null, issue_date: bool|null, total: bool|null}
     */
    public function compare(array $data): array
    {
        $taxId = TaxId::normalize(is_string($data['supplier']['tax_id'] ?? null) ? $data['supplier']['tax_id'] : null);
        $number = is_string($data['invoice_number'] ?? null) ? $data['invoice_number'] : null;
        $date = is_string($data['issue_date'] ?? null) ? $data['issue_date'] : null;
        $total = Money::cents(is_scalar($data['total'] ?? null) ? (string) $data['total'] : null);

        return [
            'supplier_tax_id' => $taxId === null ? null : $taxId === $this->taxId,
            'invoice_number' => $number === null ? null : self::sameNumber($number, $this->number),
            'issue_date' => $date === null ? null : $date === $this->date,
            'total' => $total === null ? null : $total === Money::cents($this->total),
        ];
    }

    /**
     * Campos que el QR confirma: no hace falta que los mire una persona
     * aunque la IA tuviera dudas al leerlos.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    public function confirmedFields(array $data): array
    {
        return array_keys(array_filter($this->compare($data), fn (?bool $match) => $match === true));
    }

    /**
     * El mismo número aunque cambien los espacios, las mayúsculas o los
     * separadores ("F 2026/001" y "F-2026-001"): la IA a veces lee mal un
     * guion, pero si las letras y cifras son las mismas es la misma factura.
     */
    private static function sameNumber(string $a, string $b): bool
    {
        $clean = fn (string $v) => strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $v));

        return $clean($a) !== '' && $clean($a) === $clean($b);
    }

    /**
     * @param  array<string, mixed>|null  $data  Lo guardado con toArray().
     */
    public static function fromArray(?array $data): ?self
    {
        if ($data === null || ! isset($data['tax_id'], $data['number'], $data['date'], $data['total'])) {
            return null;
        }

        return new self(
            (string) $data['tax_id'],
            (string) $data['number'],
            (string) $data['date'],
            (string) $data['total'],
            (bool) ($data['verifactu'] ?? true),
            (bool) ($data['test_environment'] ?? false),
        );
    }

    /**
     * @return array{tax_id: string, number: string, date: string, total: string, verifactu: bool, test_environment: bool}
     */
    public function toArray(): array
    {
        return [
            'tax_id' => $this->taxId,
            'number' => $this->number,
            'date' => $this->date,
            'total' => $this->total,
            'verifactu' => $this->verifactu,
            'test_environment' => $this->testEnvironment,
        ];
    }

    /**
     * @param  array<mixed>  $params
     */
    private static function param(array $params, string $name): ?string
    {
        $value = $params[$name] ?? null;

        return is_string($value) ? $value : null;
    }

    /** DD-MM-AAAA → AAAA-MM-DD, solo si es una fecha que existe. */
    private static function date(string $value): ?string
    {
        if (! preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', trim($value), $m) || ! checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('!d-m-Y', trim($value))?->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    /** "241.4" → "241.40". Admite negativos (rectificativas). */
    private static function amount(string $value): ?string
    {
        $value = trim($value);

        if (! preg_match('/^-?\d+(\.\d{1,2})?$/', $value)) {
            return null;
        }

        $cents = (int) Money::cents($value);
        $abs = abs($cents);

        return ($cents < 0 ? '-' : '').intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }
}
