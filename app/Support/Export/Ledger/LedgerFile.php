<?php

namespace App\Support\Export\Ledger;

use App\Models\Company;
use Illuminate\Support\Str;
use InvalidArgumentException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/**
 * El libro de facturas en un fichero: CSV pensado para Excel en español
 * (separador ";", coma decimal, UTF-8 con BOM) o XLSX con importes numéricos.
 */
class LedgerFile
{
    public function __construct(private readonly InvoiceLedger $ledger) {}

    /**
     * @return array{filename: string, contents: string, mime: string, rows: int}
     */
    public function build(Company $company, Period $period, string $format): array
    {
        $rows = $this->ledger->rows($company, $period);
        $name = sprintf('facturas-%s-%s', Str::slug($company->name), Str::lower($period->key));

        return match ($format) {
            'csv' => ['filename' => "{$name}.csv", 'contents' => $this->csv($rows), 'mime' => 'text/csv; charset=UTF-8', 'rows' => count($rows)],
            'xlsx' => ['filename' => "{$name}.xlsx", 'contents' => $this->xlsx($rows), 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'rows' => count($rows)],
            default => throw new InvalidArgumentException("Formato no válido: {$format}. Usa csv o xlsx."),
        };
    }

    /**
     * @param  list<list<string|int|float|null>>  $rows
     */
    private function csv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        assert($handle !== false);

        fwrite($handle, "\u{FEFF}");
        fputcsv($handle, InvoiceLedger::HEADERS, ';', escape: '');

        foreach ($rows as $row) {
            $cells = [];
            foreach ($row as $i => $value) {
                $cells[] = match (true) {
                    $value === null => '',
                    InvoiceLedger::isAmountColumn($i) => number_format((int) $value / 100, 2, ',', ''),
                    is_float($value) => rtrim(rtrim(number_format($value, 2, ',', ''), '0'), ','),
                    $i === 0 => $this->spanishDate((string) $value),
                    default => (string) $value,
                };
            }
            fputcsv($handle, $cells, ';', escape: '');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * @param  list<list<string|int|float|null>>  $rows
     */
    private function xlsx(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ledger').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(InvoiceLedger::HEADERS));

        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues(array_map(
                fn ($value, int $i) => match (true) {
                    $value === null => '',
                    InvoiceLedger::isAmountColumn($i) => round((int) $value / 100, 2),
                    $i === 0 => $this->spanishDate((string) $value),
                    default => $value,
                },
                $row,
                array_keys($row),
            )));
        }

        $writer->close();
        $contents = (string) file_get_contents($path);
        @unlink($path);

        return $contents;
    }

    private function spanishDate(string $date): string
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) ? "{$m[3]}/{$m[2]}/{$m[1]}" : $date;
    }
}
