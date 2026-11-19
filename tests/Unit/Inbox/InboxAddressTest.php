<?php

use App\Support\Inbox\InboxAddress;

it('builds sub-addresses on the configured mailbox', function () {
    config(['invoices.inbox.address' => 'Facturas@Midominio.es']);

    expect(InboxAddress::for('abc123'))->toBe('facturas+abc123@midominio.es');
});

it('extracts the token only from our own mailbox', function (string $email, ?string $token) {
    config(['invoices.inbox.address' => 'facturas@midominio.es']);

    expect(InboxAddress::extractToken($email))->toBe($token);
})->with([
    ['facturas+abc123@midominio.es', 'abc123'],
    ['FACTURAS+ABC123@MIDOMINIO.ES', 'abc123'],
    ['facturas@midominio.es', null],
    ['facturas+abc123@otrodominio.es', null],
    ['otro+abc123@midominio.es', null],
    ['facturas+abc-123@midominio.es', null],
]);
