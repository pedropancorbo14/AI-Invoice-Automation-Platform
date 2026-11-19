<?php

use App\Support\Extraction\Exceptions\UnsupportedInput;
use App\Support\Extraction\InputMode;
use App\Support\Extraction\InputPreparer;
use Tests\Support\Files;

it('sends the text of PDFs that have a text layer', function () {
    $input = (new InputPreparer)->prepare(Files::invoicePdf(), 'application/pdf', 'Panadería', 'B87654321');

    expect($input->mode)->toBe(InputMode::Text)
        ->and($input->content)->toContain('TOTAL FACTURA: 121,00 EUR')
        ->and($input->companyTaxId)->toBe('B87654321');
});

it('sends the whole PDF when it has no usable text (scans)', function () {
    $input = (new InputPreparer)->prepare(Files::pdf(), 'application/pdf');

    expect($input->mode)->toBe(InputMode::Pdf)
        ->and($input->content)->toBe(Files::pdf());
});

it('sends the whole PDF when text extraction is turned off', function () {
    config(['invoices.extraction.prefer_text' => false]);

    expect((new InputPreparer)->prepare(Files::invoicePdf(), 'application/pdf')->mode)->toBe(InputMode::Pdf);
});

it('keeps reasonable photos as they are', function () {
    $photo = Files::photo(1200, 1600);
    $input = (new InputPreparer)->prepare($photo, 'image/jpeg');

    expect($input->mode)->toBe(InputMode::Image)
        ->and($input->content)->toBe($photo)
        ->and($input->mediaType)->toBe('image/jpeg');
});

it('downscales huge photos to save tokens', function () {
    config(['invoices.extraction.max_image_side' => 800]);

    $input = (new InputPreparer)->prepare(Files::photo(1200, 1600, 'png'), 'image/png');
    [$width, $height] = getimagesizefromstring($input->content);

    expect($input->mediaType)->toBe('image/jpeg')
        ->and([$width, $height])->toBe([600, 800]);
});

it('rejects broken images and unsupported formats', function (string $content, string $mime) {
    (new InputPreparer)->prepare($content, $mime);
})->throws(UnsupportedInput::class)->with([
    'jpg roto' => ["\xFF\xD8\xFFbasura", 'image/jpeg'],
    'correo' => ['From: a@b.c', 'message/rfc822'],
]);
