<?php

it('ships faker in production because demo:reset seeds with factories', function () {
    // La imagen de producción se instala con --no-dev, y demo:reset usa las
    // factories (que llaman a fake()) cada noche en la demo pública.
    $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true);

    expect($composer['require'])->toHaveKey('fakerphp/faker')
        ->and($composer['require-dev'])->not->toHaveKey('fakerphp/faker');
});
