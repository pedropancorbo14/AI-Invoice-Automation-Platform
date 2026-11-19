<?php

use App\Enums\DocumentEventType;
use App\Enums\DocumentStatus;
use App\Exceptions\InvalidDocumentTransition;
use App\Models\Document;
use App\Models\DocumentEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;

it('starts as received with a creation event', function () {
    $document = Document::factory()->create();

    expect($document->fresh()->status)->toBe(DocumentStatus::Received)
        ->and($document->events)->toHaveCount(1)
        ->and($document->events->first()->type)->toBe(DocumentEventType::Created);
});

it('walks the happy path and audits every step', function () {
    $document = Document::factory()->create();

    foreach ([DocumentStatus::Classified, DocumentStatus::Extracted, DocumentStatus::Validated, DocumentStatus::Exported] as $status) {
        $document->transitionTo($status);
    }

    $changes = $document->events()->where('type', DocumentEventType::StatusChanged)->get();

    expect($document->fresh()->status)->toBe(DocumentStatus::Exported)
        ->and($changes->map(fn (DocumentEvent $e) => [$e->from_status, $e->to_status])->all())->toBe([
            [DocumentStatus::Received, DocumentStatus::Classified],
            [DocumentStatus::Classified, DocumentStatus::Extracted],
            [DocumentStatus::Extracted, DocumentStatus::Validated],
            [DocumentStatus::Validated, DocumentStatus::Exported],
        ]);
});

it('records who made the change and why', function () {
    $document = Document::factory()->create();
    $reviewer = User::factory()->forAccount($document->account)->create();

    $document->transitionTo(DocumentStatus::NeedsReview, 'El total no cuadra: 121,00 ≠ 100,00 + 20,00');
    $document->transitionTo(DocumentStatus::Validated, by: $reviewer, payload: ['field' => 'total']);

    [$toReview, $approved] = $document->events()->where('type', DocumentEventType::StatusChanged)->get()->all();

    expect($document->fresh()->status_reason)->toBeNull()
        ->and($toReview->user_id)->toBeNull()
        ->and($toReview->reason)->toBe('El total no cuadra: 121,00 ≠ 100,00 + 20,00')
        ->and($approved->user_id)->toBe($reviewer->id)
        ->and($approved->payload)->toBe(['field' => 'total']);
});

it('rejects transitions that are not allowed', function () {
    $document = Document::factory()->create();

    expect(fn () => $document->transitionTo(DocumentStatus::Exported))
        ->toThrow(InvalidDocumentTransition::class);

    expect($document->fresh()->status)->toBe(DocumentStatus::Received)
        ->and($document->events()->count())->toBe(1);
});

it('validates against the stored status, not a stale model', function () {
    $document = Document::factory()->create();
    $stale = Document::findOrFail($document->id);

    $document->transitionTo(DocumentStatus::Ignored);

    expect(fn () => $stale->transitionTo(DocumentStatus::Classified))
        ->toThrow(InvalidDocumentTransition::class);
});

it('keeps the audit trail immutable', function () {
    $event = Document::factory()->create()->events()->firstOrFail();

    expect(fn () => $event->update(['reason' => 'x']))->toThrow(LogicException::class)
        ->and(fn () => $event->delete())->toThrow(LogicException::class);
});

it('does not allow setting the status by mass assignment', function () {
    $document = Document::factory()->create();

    expect(fn () => $document->fill(['status' => DocumentStatus::Exported]))
        ->toThrow(MassAssignmentException::class);

    expect($document->status)->toBe(DocumentStatus::Received);
});
