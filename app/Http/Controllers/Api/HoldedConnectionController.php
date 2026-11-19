<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CompanyResource;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Conecta un cliente con su cuenta de Holded (solo administradores). La API
 * key se guarda cifrada y nunca se devuelve.
 */
class HoldedConnectionController extends Controller
{
    public function update(Request $request, Company $company): CompanyResource
    {
        Gate::authorize('update', $company);

        $data = $request->validate(['api_key' => ['required', 'string', 'min:10', 'max:200']]);
        $company->forceFill(['holded_api_key' => trim($data['api_key'])])->save();

        return new CompanyResource($company);
    }

    public function destroy(Company $company): CompanyResource
    {
        Gate::authorize('update', $company);

        $company->forceFill(['holded_api_key' => null])->save();

        return new CompanyResource($company);
    }
}
