<?php

namespace Database\Factories;

use App\Enums\DocumentSource;
use App\Models\Account;
use App\Models\Company;
use App\Models\Document;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    public function definition(): array
    {
        $contents = fake()->sha256();

        return [
            'account_id' => Account::factory(),
            'company_id' => null,
            'source' => DocumentSource::Email,
            'disk' => 'documents',
            'path' => 'accounts/0/'.fake()->uuid().'.pdf',
            'original_filename' => 'factura-'.fake()->numerify('####').'.pdf',
            'mime_type' => 'application/pdf',
            'size' => strlen($contents),
            'sha256' => hash('sha256', $contents),
        ];
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn () => [
            'account_id' => $company->account_id,
            'company_id' => $company->getKey(),
        ]);
    }
}
