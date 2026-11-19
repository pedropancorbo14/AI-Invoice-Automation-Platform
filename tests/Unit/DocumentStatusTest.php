<?php

use App\Enums\DocumentStatus;

it('allows the expected transitions', function (DocumentStatus $from, DocumentStatus $to) {
    expect($from->canTransitionTo($to))->toBeTrue();
})->with([
    'recibido → clasificado' => [DocumentStatus::Received, DocumentStatus::Classified],
    'clasificado → extraído' => [DocumentStatus::Classified, DocumentStatus::Extracted],
    'extraído → validado' => [DocumentStatus::Extracted, DocumentStatus::Validated],
    'validado → exportado' => [DocumentStatus::Validated, DocumentStatus::Exported],
    'clasificado → ignorado (no es factura)' => [DocumentStatus::Classified, DocumentStatus::Ignored],
    'extraído → revisión' => [DocumentStatus::Extracted, DocumentStatus::NeedsReview],
    'validado → revisión (falla la exportación)' => [DocumentStatus::Validated, DocumentStatus::NeedsReview],
    'revisión → validado (aprobado)' => [DocumentStatus::NeedsReview, DocumentStatus::Validated],
    'revisión → recibido (llega el PDF que faltaba)' => [DocumentStatus::NeedsReview, DocumentStatus::Received],
    'fallido → recibido (reintento)' => [DocumentStatus::Failed, DocumentStatus::Received],
]);

it('rejects invalid transitions', function (DocumentStatus $from, DocumentStatus $to) {
    expect($from->canTransitionTo($to))->toBeFalse();
})->with([
    'saltarse la extracción' => [DocumentStatus::Received, DocumentStatus::Validated],
    'exportar sin validar' => [DocumentStatus::Extracted, DocumentStatus::Exported],
    'volver atrás' => [DocumentStatus::Validated, DocumentStatus::Extracted],
    'a sí mismo' => [DocumentStatus::Received, DocumentStatus::Received],
    'salir de exportado' => [DocumentStatus::Exported, DocumentStatus::NeedsReview],
]);

it('treats only exported as terminal', function () {
    $terminal = array_filter(DocumentStatus::cases(), fn (DocumentStatus $s) => $s->isTerminal());

    expect(array_values($terminal))->toBe([DocumentStatus::Exported]);
});

it('only transitions to known states', function () {
    foreach (DocumentStatus::cases() as $status) {
        expect($status->allowedTransitions())->not->toContain($status);
    }
});
