<?php

namespace Database\Factories;

use App\Models\InboundEmail;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InboundEmail>
 */
class InboundEmailFactory extends Factory
{
    public function definition(): array
    {
        return [
            'account_id' => null,
            'fingerprint' => hash('sha256', fake()->uuid()),
            'message_id' => fake()->uuid().'@mail.test',
            'from_address' => fake()->safeEmail(),
            'from_name' => fake()->name(),
            'recipient' => 'facturas@example.com',
            'subject' => 'Factura '.fake()->numerify('####'),
            'sent_at' => now(),
            'disk' => 'documents',
            'raw_path' => 'inbound/'.fake()->uuid().'.eml',
            'size' => 100,
        ];
    }
}
