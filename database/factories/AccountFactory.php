<?php

namespace Database\Factories;

use App\Models\Account;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Gestoría '.fake()->unique()->lastName(),
        ];
    }

    /**
     * Autónomo o pyme sin gestoría: una sola empresa.
     */
    public function singleCompany(): static
    {
        return $this->state(['name' => fake()->company(), 'single_company' => true]);
    }
}
