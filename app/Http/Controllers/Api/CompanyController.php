<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CompanyResource;
use App\Models\Account;
use App\Models\Company;
use App\Support\Tenancy\CurrentAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CompanyController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Company::class);

        return CompanyResource::collection(Company::query()->orderBy('name')->get());
    }

    public function store(Request $request, CurrentAccount $currentAccount): CompanyResource
    {
        Gate::authorize('create', Company::class);

        $request->merge(['tax_id' => Company::normalizeTaxId((string) $request->input('tax_id'))]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'tax_id' => [
                'required', 'string', 'max:20',
                Rule::unique('companies')->where('account_id', $currentAccount->id()),
            ],
        ]);

        $singleCompany = (bool) Account::query()->whereKey($currentAccount->id())->value('single_company');

        if ($singleCompany && Company::query()->exists()) {
            throw ValidationException::withMessages([
                'name' => 'La cuenta es de empresa única y ya tiene su empresa.',
            ]);
        }

        return new CompanyResource(Company::create($data));
    }

    public function show(Company $company): CompanyResource
    {
        Gate::authorize('view', $company);

        return new CompanyResource($company);
    }
}
