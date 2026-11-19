<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cada cliente de la cuenta, con su CIF y razón social.
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('tax_id', 20);
            // Token de la dirección de entrada: facturas+<token>@dominio (fase 2).
            $table->string('inbox_token', 32)->unique();
            $table->timestamps();

            $table->unique(['account_id', 'tax_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
