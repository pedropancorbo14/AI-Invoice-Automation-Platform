<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Document;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class UploadPageController extends Controller
{
    public function __invoke(#[CurrentUser] User $user): View
    {
        Gate::authorize('create', Document::class);

        return view('documents.upload', [
            'user' => $user,
            'account' => $user->account()->firstOrFail(),
            'companies' => Company::query()->orderBy('name')->get(),
        ]);
    }
}
