<?php

namespace App\Http\Controllers\Api;

use App\Actions\UploadDocuments;
use App\Http\Controllers\Controller;
use App\Http\Requests\UploadDocumentsRequest;
use App\Http\Resources\DocumentResource;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/**
 * Subida manual. La usan la API y la página de arrastrar y soltar.
 */
class DocumentUploadController extends Controller
{
    public function __invoke(UploadDocumentsRequest $request, UploadDocuments $upload, #[CurrentUser] User $user): JsonResponse
    {
        $result = $upload->handle($user->account()->firstOrFail(), $request->uploadedFiles(), $request->company());

        return response()->json([
            'data' => [
                'created' => DocumentResource::collection($result['created']),
                'duplicates' => $result['duplicates'],
                'rejected' => $result['rejected'],
            ],
        ], $result['created'] === [] ? 200 : 201);
    }
}
