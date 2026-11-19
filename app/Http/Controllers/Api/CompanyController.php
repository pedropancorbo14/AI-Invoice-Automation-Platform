<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CompanyResource;
use App\Models\Company;
use App\Support\Tenancy\CurrentAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

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

        return new CompanyResource(Company::create($data));
    }

    public function show(Company $company): CompanyResource
    {
        Gate::authorize('view', $company);

        return new CompanyResource($company);
    }
}
