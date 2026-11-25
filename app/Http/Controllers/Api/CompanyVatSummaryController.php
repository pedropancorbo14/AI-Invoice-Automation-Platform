<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Support\Export\Ledger\Period;
use App\Support\Tax\VatSummary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Resumen de IVA y retenciones de un cliente en un periodo (normalmente un
 * trimestre), como base para los modelos 303, 111 y 130.
 */
class CompanyVatSummaryController extends Controller
{
    public function __invoke(Request $request, Company $company, VatSummary $summary): JsonResponse
    {
        Gate::authorize('view', $company);

        $data = $request->validate(['period' => ['required', 'string']]);

        try {
            $period = Period::parse($data['period']);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['period' => $e->getMessage()]);
        }

        return response()->json(['data' => $summary->build($company, $period)]);
    }
}
