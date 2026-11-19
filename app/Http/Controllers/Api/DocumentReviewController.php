<?php

namespace App\Http\Controllers\Api;

use App\Actions\Review\ApproveDocument;
use App\Actions\Review\DiscardDocument;
use App\Actions\Review\ReplaceDocumentFile;
use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentResource;
use App\Models\Document;
use App\Models\User;
use App\Support\Review\NeedsConfirmation;
use App\Support\Validation\RuleFailure;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Acciones de la revisión por excepción.
 */
class DocumentReviewController extends Controller
{
    public function approve(Request $request, Document $document, ApproveDocument $approve, #[CurrentUser] User $user): JsonResponse
    {
        Gate::authorize('update', $document);

        $validated = $request->validate([
            'data' => ['required', 'array'],
            'data.document_type' => ['nullable', Rule::in(['invoice', 'simplified_invoice', 'credit_note'])],
            'data.tax_breakdown' => ['present', 'array'],
            'supplier.ledger_account' => ['nullable', 'string', 'max:20'],
            'supplier.category' => ['nullable', 'string', 'max:100'],
            'force' => ['boolean'],
        ]);

        try {
            $document = $approve->handle(
                $document->load(['company', 'latestExtraction']),
                $user,
                // Entero: validate() solo devolvería las claves anidadas con regla.
                (array) $request->input('data'),
                (array) ($validated['supplier'] ?? []),
                (bool) ($validated['force'] ?? false),
            );
        } catch (NeedsConfirmation $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'needs_confirmation' => true,
                'warnings' => array_map(fn (RuleFailure $f) => $f->toArray(), $e->warnings),
            ], 422);
        } catch (InvalidArgumentException $e) {
            return $this->unprocessable($e->getMessage());
        }

        return (new DocumentResource($document->load(['events', 'latestExtraction'])))->response();
    }

    public function notInvoice(Document $document, DiscardDocument $discard, #[CurrentUser] User $user): JsonResponse
    {
        Gate::authorize('update', $document);

        try {
            return (new DocumentResource($discard->notInvoice($document, $user)))->response();
        } catch (InvalidArgumentException $e) {
            return $this->unprocessable($e->getMessage());
        }
    }

    public function duplicate(Request $request, Document $document, DiscardDocument $discard, #[CurrentUser] User $user): JsonResponse
    {
        Gate::authorize('update', $document);

        $data = $request->validate(['of_document_id' => ['nullable', 'integer']]);
        $original = isset($data['of_document_id']) ? Document::query()->findOrFail((int) $data['of_document_id']) : null;

        try {
            return (new DocumentResource($discard->duplicateOf($document, $user, $original)))->response();
        } catch (InvalidArgumentException $e) {
            return $this->unprocessable($e->getMessage());
        }
    }

    public function replaceFile(Request $request, Document $document, ReplaceDocumentFile $replace, #[CurrentUser] User $user): JsonResponse
    {
        Gate::authorize('update', $document);

        $maxKb = intdiv((int) config('invoices.inbox.attachments.max_bytes'), 1024);
        $request->validate(['file' => ['required', 'file', "max:{$maxKb}"]]);
        $file = $request->file('file');
        abort_unless($file instanceof UploadedFile, 422);

        try {
            $document = $replace->handle($document, $user, (string) $file->get(), $file->getClientOriginalName());
        } catch (InvalidArgumentException $e) {
            return $this->unprocessable($e->getMessage());
        }

        return (new DocumentResource($document))->response();
    }

    private function unprocessable(string $message): JsonResponse
    {
        return response()->json(['message' => $message], 422);
    }
}
