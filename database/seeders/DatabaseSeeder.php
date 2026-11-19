<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Cuenta demo para probar el proyecto sin configurar nada.
     * Usuarios: admin@demo.test y revisor@demo.test (contraseña: password).
     * Incluye facturas de ejemplo para probar el panel (DemoInvoicesSeeder).
     */
    public function run(): void
    {
        $account = Account::factory()->create(['name' => 'Gestoría Demo']);

        User::factory()->admin()->forAccount($account)->create([
            'name' => 'Admin Demo',
            'email' => 'admin@demo.test',
        ]);

        User::factory()->forAccount($account)->create([
            'name' => 'Revisor Demo',
            'email' => 'revisor@demo.test',
        ]);

        Company::factory()->for($account)->createMany([
            ['name' => 'Panadería La Espiga S.L.', 'tax_id' => 'B34567891'],
            ['name' => 'Talleres Martínez S.L.', 'tax_id' => 'B45678919'],
        ]);

        // Facturas ficticias: unas validadas solas y otras pendientes de revisar.
        $this->call(DemoInvoicesSeeder::class);
    }
}
