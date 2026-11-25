<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_extractions', function (Blueprint $table) {
            // Lo que dice el QR de VERI*FACTU de la factura, si lo lleva.
            $table->json('verifactu')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('document_extractions', function (Blueprint $table) {
            $table->dropColumn('verifactu');
        });
    }
};
