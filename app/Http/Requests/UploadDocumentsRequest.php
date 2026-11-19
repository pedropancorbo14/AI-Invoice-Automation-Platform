<?php

namespace App\Http\Requests;

use App\Models\Company;
use App\Models\Document;
use App\Support\Tenancy\CurrentAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UploadDocumentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', Document::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxKb = intdiv((int) config('invoices.inbox.attachments.max_bytes'), 1024);

        return [
            'files' => ['required', 'array', 'min:1', 'max:20'],
            'files.*' => ['required', 'file', "max:{$maxKb}"],
            'company_id' => [
                'nullable', 'integer',
                Rule::exists('companies', 'id')->where('account_id', app(CurrentAccount::class)->id()),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['files' => 'ficheros', 'files.*' => 'fichero', 'company_id' => 'cliente'];
    }

    /**
     * @return list<UploadedFile>
     */
    public function uploadedFiles(): array
    {
        return array_values(array_filter(
            (array) $this->file('files', []),
            fn ($file) => $file instanceof UploadedFile,
        ));
    }

    public function company(): ?Company
    {
        $id = $this->validated('company_id');

        return $id === null ? null : Company::query()->findOrFail((int) $id);
    }
}
