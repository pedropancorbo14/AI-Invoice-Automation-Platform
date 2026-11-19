<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'name' => fake()->company().' S.L.',
            'tax_id' => 'B'.fake()->unique()->numerify('########'),
        ];
    }
}
