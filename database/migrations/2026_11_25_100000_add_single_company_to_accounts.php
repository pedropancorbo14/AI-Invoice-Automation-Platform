<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            // Empresa única (autónomo o pyme sin gestoría): todo va a su empresa
            // y el panel no habla de "clientes".
            $table->boolean('single_company')->default(false)->after('digest_frequency');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('single_company');
        });
    }
};
