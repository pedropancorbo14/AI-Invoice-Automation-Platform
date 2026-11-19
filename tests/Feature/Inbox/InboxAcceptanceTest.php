<?php

use App\Actions\ReceiveInboundEmail;
use App\Enums\DocumentStatus;
use App\Enums\InboundEmailStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\Document;
use App\Models\InboundEmail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Files;
use Tests\Support\TestMail;

/*
| Criterio de salida de la fase 2: correos variados (varios adjuntos, sin
| adjunto, repetidos...) y cada uno acaba en el estado correcto.
*/

beforeEach(function () {
    Storage::fake('documents');

    $this->account = Account::factory()->create();
    $this->company = Company::factory()->for($this->account)->create();
});

/**
 * Entrega un correo en el buzón como lo haría el comando IMAP.
 */
function deliver(TestMail|string $mail): ?InboundEmail
{
    $raw = $mail instanceof TestMail ? $mail->toString() : $mail;
    $inbound = app(ReceiveInboundEmail::class)->handle($raw);

    return $inbound === null ? null : InboundEmail::withoutGlobalScopes()->findOrFail($inbound->id);
}

/** Reenvío automático a la dirección del cliente de prueba. */
function forwarded(): TestMail
{
    return TestMail::make()->deliveredTo(test()->company->inboxAddress());
}

/**
 * @return Illuminate\Support\Collection<int, Document>
 */
function documents(): Illuminate\Support\Collection
{
    return Document::withoutGlobalScopes()->orderBy('id')->get();
}

/**
 * @return list<string>
 */
function outcomes(InboundEmail $inbound): array
{
    return array_column($inbound->summary['attachments'] ?? [], 'outcome');
}

it('1. guarda un PDF reenviado a la dirección del cliente', function () {
    $inbound = deliver(forwarded()->attach(Files::pdf(), 'Factura 2026-001.pdf', 'application/pdf'));

    expect($inbound->status)->toBe(InboundEmailStatus::Processed)
        ->and($inbound->account_id)->toBe($this->account->id)
        ->and($inbound->company_id)->toBe($this->company->id)
        ->and($inbound->recipient)->toBe($this->company->inboxAddress());

    $document = documents()->sole();

    expect($document->status)->toBe(DocumentStatus::Received)
        ->and($document->company_id)->toBe($this->company->id)
        ->and($document->inbound_email_id)->toBe($inbound->id)
        ->and($document->original_filename)->toBe('Factura 2026-001.pdf')
        ->and($document->mime_type)->toBe('application/pdf');

    Storage::disk('documents')->assertExists($document->path);
    Storage::disk('documents')->assertExists($inbound->raw_path);
});

it('2. guarda cada PDF de un correo con varios adjuntos', function () {
    deliver(forwarded()
        ->attach(Files::pdf('a'), 'a.pdf', 'application/pdf')
        ->attach(Files::pdf('b'), 'b.pdf', 'application/pdf')
        ->attach(Files::pdf('c'), 'c.pdf', 'application/pdf'));

    expect(documents())->toHaveCount(3);
});

it('3. descarta el logo de la firma y se queda con la factura', function () {
    $inbound = deliver(forwarded()
        ->html('<p>Adjunto factura</p><img src="cid:logo.png">')
        ->embed(Files::logo(), 'logo.png', 'image/png')
        ->attach(Files::pdf(), 'factura.pdf', 'application/pdf'));

    expect(documents())->toHaveCount(1)
        ->and(outcomes($inbound))->toEqualCanonicalizing(['too_small', 'stored']);
});

it('4. guarda una foto de móvil (JPG)', function () {
    deliver(forwarded()->attach(Files::photo(), 'IMG_2041.JPG', 'image/jpeg'));

    expect(documents()->sole()->mime_type)->toBe('image/jpeg');
});

it('5. guarda un escaneo en PNG', function () {
    deliver(forwarded()->attach(Files::photo(1240, 1754, 'png'), 'scan.png', 'image/png'));

    expect(documents()->sole()->mime_type)->toBe('image/png');
});

it('6. guarda una foto HEIC del iPhone', function () {
    deliver(forwarded()->attach(Files::heic(), 'IMG_0001.HEIC', 'image/heic'));

    expect(documents()->sole())
        ->mime_type->toBe('image/heic')
        ->original_filename->toBe('IMG_0001.HEIC');
});

it('7. reconoce un PDF por su contenido aunque venga sin extensión ni tipo', function () {
    deliver(forwarded()->attach(Files::pdf(), 'factura', 'application/octet-stream'));

    expect(documents()->sole())
        ->mime_type->toBe('application/pdf')
        ->original_filename->toBe('factura.pdf');
});

it('8. marca como sin documentos un correo sin adjuntos ni enlaces', function () {
    $inbound = deliver(forwarded()->text('Hola, ¿me confirmas que recibiste la factura de ayer?'));

    expect($inbound->status)->toBe(InboundEmailStatus::NoDocuments)
        ->and($inbound->status_reason)->toBe('El correo no trae adjuntos ni enlaces de descarga.')
        ->and(documents())->toBeEmpty();
});

it('9. manda a revisión una factura por enlace (HTML)', function () {
    $inbound = deliver(forwarded()->html(
        '<p>Su factura de noviembre ya está disponible.</p><a href="https://portal.proveedor.test/f/8812">Descargar factura</a>'
    ));

    $document = documents()->sole();

    expect($inbound->status)->toBe(InboundEmailStatus::Processed)
        ->and($inbound->summary['links'])->toBe(['https://portal.proveedor.test/f/8812'])
        ->and($document->status)->toBe(DocumentStatus::NeedsReview)
        ->and($document->status_reason)->toStartWith('Factura por enlace')
        ->and($document->mime_type)->toBe('message/rfc822');
});

it('10. manda a revisión una factura por enlace (texto plano)', function () {
    deliver(forwarded()->text("Puede descargar su factura aquí:\nhttps://clientes.luz.test/descargas/2026-11.pdf\nGracias."));

    expect(documents()->sole()->status)->toBe(DocumentStatus::NeedsReview);
});

it('11. no confunde los enlaces de un boletín con una factura', function () {
    $inbound = deliver(forwarded()->html(
        '<p>Novedades</p><a href="https://facebook.com/proveedor">Facebook</a> <a href="https://proveedor.test/unsubscribe?id=1">Darse de baja</a>'
    ));

    expect($inbound->status)->toBe(InboundEmailStatus::NoDocuments)
        ->and(documents())->toBeEmpty();
});

it('12. no procesa de nuevo un PDF que ya llegó en otro correo', function () {
    deliver(forwarded()->attach(Files::pdf('igual'), 'factura.pdf', 'application/pdf'));
    $second = deliver(forwarded()->subject('RE: Factura')->attach(Files::pdf('igual'), 'copia.pdf', 'application/pdf'));

    expect(documents())->toHaveCount(1)
        ->and($second->status)->toBe(InboundEmailStatus::Processed)
        ->and($second->status_reason)->toBe('Todos los adjuntos ya se habían recibido antes.')
        ->and($second->summary['attachments'][0]['document_id'])->toBe(documents()->sole()->id);
});

it('13. ignora el mismo correo recibido dos veces (mismo Message-ID)', function () {
    $mail = forwarded()->messageId('repetido@proveedor.test')->attach(Files::pdf(), 'f.pdf', 'application/pdf');

    expect(deliver($mail))->not->toBeNull()
        ->and(deliver($mail))->toBeNull()
        ->and(InboundEmail::withoutGlobalScopes()->count())->toBe(1)
        ->and(documents())->toHaveCount(1);
});

it('14. no duplica un PDF adjuntado dos veces en el mismo correo', function () {
    $inbound = deliver(forwarded()
        ->attach(Files::pdf('x'), 'factura.pdf', 'application/pdf')
        ->attach(Files::pdf('x'), 'factura (1).pdf', 'application/pdf'));

    expect(documents())->toHaveCount(1)
        ->and(outcomes($inbound))->toBe(['stored', 'duplicate']);
});

it('15. abre un correo reenviado como adjunto (Outlook)', function () {
    $original = TestMail::make()->attach(Files::pdf('outlook'), 'Factura.pdf', 'application/pdf');

    deliver(forwarded()->attachEmail($original));

    expect(documents()->sole()->original_filename)->toBe('Factura.pdf');
});

it('16. abre un correo adjunto en 7bit, como lo manda Outlook de verdad', function () {
    $token = $this->company->inbox_token;
    $pdf = base64_encode(Files::pdf('7bit'));
    $raw = <<<EML
        From: Ana <ana@cliente.test>
        To: facturas+{$token}@example.com
        Subject: RV: Factura octubre
        Message-ID: <outlook-7bit@cliente.test>
        MIME-Version: 1.0
        Content-Type: multipart/mixed; boundary="outer"

        --outer
        Content-Type: text/plain; charset=utf-8

        Te reenvío la factura.
        --outer
        Content-Type: message/rfc822
        Content-Transfer-Encoding: 7bit
        Content-Disposition: attachment

        From: Proveedor <facturas@proveedor.test>
        Subject: Factura octubre
        MIME-Version: 1.0
        Content-Type: multipart/mixed; boundary="inner"

        --inner
        Content-Type: text/plain

        Adjunto.
        --inner
        Content-Type: application/pdf; name="F-10.pdf"
        Content-Transfer-Encoding: base64
        Content-Disposition: attachment; filename="F-10.pdf"

        {$pdf}
        --inner--
        --outer--
        EML;

    deliver(str_replace("\n", "\r\n", $raw));

    expect(documents()->sole()->original_filename)->toBe('F-10.pdf');
});

it('17. deja en "sin asignar" lo que llega a la dirección de la cuenta', function () {
    $inbound = deliver(TestMail::make()
        ->deliveredTo($this->account->inboxAddress())
        ->attach(Files::pdf(), 'factura.pdf', 'application/pdf'));

    expect($inbound->account_id)->toBe($this->account->id)
        ->and($inbound->company_id)->toBeNull()
        ->and(documents()->sole()->company_id)->toBeNull();
});

it('18. no asigna a nadie un correo sin token', function () {
    $inbound = deliver(TestMail::make()->to('facturas@example.com')->attach(Files::pdf(), 'f.pdf', 'application/pdf'));

    expect($inbound->status)->toBe(InboundEmailStatus::Unrouted)
        ->and($inbound->account_id)->toBeNull()
        ->and(documents())->toBeEmpty();
});

it('19. no asigna a nadie un correo con un token que no existe', function () {
    $inbound = deliver(TestMail::make()->deliveredTo('facturas+noexiste123@example.com')
        ->attach(Files::pdf(), 'f.pdf', 'application/pdf'));

    expect($inbound->status)->toBe(InboundEmailStatus::Unrouted)
        ->and($inbound->status_reason)->toContain('facturas+noexiste123@example.com');
});

it('20. detecta el correo de confirmación de reenvío de Gmail', function () {
    $inbound = deliver(TestMail::make()
        ->from('forwarding-noreply@google.com', 'Equipo de Gmail')
        ->to($this->company->inboxAddress())
        ->subject('(#123456789) Confirmación de reenvío de Gmail - Recibir correo de ana@gmail.com')
        ->text("ana@gmail.com ha solicitado reenviar correo automáticamente.\n\nCódigo de confirmación: 123456789\n\nhttps://mail-settings.google.com/mail/vf-abc123"));

    expect($inbound->status)->toBe(InboundEmailStatus::ForwardingConfirmation)
        ->and($inbound->company_id)->toBe($this->company->id)
        ->and($inbound->summary)->toBe([
            'confirmation_code' => '123456789',
            'confirmation_url' => 'https://mail-settings.google.com/mail/vf-abc123',
        ])
        ->and(documents())->toBeEmpty();
});

it('21. enruta por el destinatario directo y sin importar mayúsculas', function () {
    $inbound = deliver(TestMail::make()
        ->to(strtoupper($this->company->inboxAddress()))
        ->attach(Files::pdf(), 'f.pdf', 'application/pdf'));

    expect($inbound->company_id)->toBe($this->company->id);
});

it('22. ignora adjuntos que no son facturas (.ics, winmail.dat, firmas digitales)', function () {
    $inbound = deliver(forwarded()
        ->attach("BEGIN:VCALENDAR\r\nEND:VCALENDAR", 'reunion.ics', 'text/calendar')
        ->attach(random_bytes(2048), 'winmail.dat', 'application/ms-tnef')
        ->attach(random_bytes(1024), 'smime.p7s', 'application/pkcs7-signature'));

    expect($inbound->status)->toBe(InboundEmailStatus::NoDocuments)
        ->and($inbound->status_reason)->toBe('Ningún adjunto parece una factura.')
        ->and(outcomes($inbound))->toBe(['unsupported', 'unsupported', 'unsupported']);
});

it('23. descarta banners anchos aunque pesen', function () {
    $inbound = deliver(forwarded()
        ->attach(Files::banner(), 'banner.jpg', 'image/jpeg')
        ->attach(Files::pdf(), 'f.pdf', 'application/pdf'));

    expect(outcomes($inbound))->toBe(['too_small', 'stored']);
});
