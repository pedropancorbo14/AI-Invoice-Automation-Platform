<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\LedgerExport;
use App\Models\Company;
use App\Models\User;
use App\Support\Export\Ledger\LedgerFile;
use App\Support\Export\Ledger\Period;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Libro de facturas de un cliente por periodo: descarga o envío por email.
 */
class CompanyExportController extends Controller
{
    public function download(Request $request, Company $company, LedgerFile $files): Response
    {
        Gate::authorize('view', $company);

        [$period, $format] = $this->options($request);
        $file = $files->build($company, $period, $format);

        return response($file['contents'], 200, [
            'Content-Type' => $file['mime'],
            'Content-Disposition' => 'attachment; filename="'.$file['filename'].'"',
        ]);
    }

    public function email(Request $request, Company $company, LedgerFile $files, #[CurrentUser] User $user): JsonResponse
    {
        Gate::authorize('view', $company);

        [$period, $format] = $this->options($request);
        $to = (string) ($request->validate(['email' => ['nullable', 'email']])['email'] ?? $user->email);
        $file = $files->build($company, $period, $format);

        Mail::to($to)->send(new LedgerExport($company, $period, $file));

        return response()->json(['data' => ['sent_to' => $to, 'filename' => $file['filename'], 'rows' => $file['rows']]]);
    }

    /**
     * @return array{0: Period, 1: string}
     */
    private function options(Request $request): array
    {
        $data = $request->validate([
            'period' => ['required', 'string'],
            'format' => ['nullable', 'in:csv,xlsx'],
        ]);

        try {
            return [Period::parse($data['period']), $data['format'] ?? 'csv'];
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['period' => $e->getMessage()]);
        }
    }
}
