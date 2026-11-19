<?php

use App\Support\Inbox\FileType;
use Tests\Support\Files;

it('detects files by their content', function (string $content, ?FileType $expected) {
    expect(FileType::detect($content))->toBe($expected);
})->with([
    'pdf' => [Files::pdf(), FileType::Pdf],
    'pdf con basura delante' => ["\r\n".Files::pdf(), FileType::Pdf],
    'jpeg' => ["\xFF\xD8\xFF\xE0".str_repeat('x', 100), FileType::Jpeg],
    'png' => ["\x89PNG\r\n\x1A\n".str_repeat('x', 100), FileType::Png],
    'heic' => [Files::heic(), FileType::Heic],
    'texto' => ['hola', null],
    'zip' => ["PK\x03\x04", null],
    'vacío' => ['', null],
]);
