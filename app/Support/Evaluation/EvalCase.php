<?php

namespace App\Support\Evaluation;

use App\Support\Inbox\FileType;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

/**
 * Una factura del dataset con su verdad anotada (mismo nombre, .json).
 */
final readonly class EvalCase
{
    /**
     * @param  array<string, mixed>  $expected
     * @param  list<string>  $tags
     */
    public function __construct(
        public string $name,
        public string $path,
        public string $mimeType,
        public array $expected,
        public array $tags,
        public ?string $companyName,
        public ?string $companyTaxId,
    ) {}

    /**
     * @return list<self>
     */
    public static function load(string $directory): array
    {
        if (! File::isDirectory($directory)) {
            throw new InvalidArgumentException("No existe la carpeta del dataset: {$directory}");
        }

        $cases = [];

        foreach (File::files($directory) as $file) {
            if ($file->getExtension() === 'json') {
                continue;
            }

            $truthPath = $file->getPath().'/'.$file->getFilenameWithoutExtension().'.json';

            if (! File::exists($truthPath)) {
                continue;
            }

            $type = FileType::detect((string) File::get($file->getPathname()));
            $truth = json_decode((string) File::get($truthPath), true);

            if ($type === null || ! is_array($truth) || ! is_array($truth['expected'] ?? null)) {
                throw new InvalidArgumentException("Caso mal formado: {$file->getFilename()} (fichero no soportado o JSON sin \"expected\").");
            }

            $cases[] = new self(
                name: $file->getFilenameWithoutExtension(),
                path: $file->getPathname(),
                mimeType: $type->mimeType(),
                expected: $truth['expected'],
                tags: array_values(array_map('strval', (array) ($truth['tags'] ?? []))),
                companyName: $truth['company']['name'] ?? null,
                companyTaxId: $truth['company']['tax_id'] ?? null,
            );
        }

        usort($cases, fn (self $a, self $b) => strcmp($a->name, $b->name));

        return $cases;
    }
}
