<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Supplier;
use App\Support\CompanyName;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Supplier>
 */
class SupplierFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->company().' S.L.';

        return [
            'company_id' => Company::factory(),
            'account_id' => fn (array $attributes) => Company::withoutGlobalScopes()->whereKey($attributes['company_id'])->value('account_id'),
            'tax_id' => 'B12345674',
            'name' => $name,
            'normalized_name' => CompanyName::normalize($name),
        ];
    }

    public function known(int $validated = 1): static
    {
        return $this->afterCreating(function (Supplier $supplier) use ($validated) {
            $supplier->forceFill(['validated_count' => $validated, 'last_validated_at' => now()])->save();
        });
    }
}
