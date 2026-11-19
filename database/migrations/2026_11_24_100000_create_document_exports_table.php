<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Qué se ha exportado a dónde. Una fila por documento y destino:
        // así nunca se exporta dos veces.
        Schema::create('document_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->string('target');
            // pending | exported | skipped | failed
            $table->string('status')->default('pending');
            // Id en el sistema de destino (p. ej. el documento de Holded).
            $table->string('external_id')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('exported_at')->nullable();
            $table->timestamps();

            $table->unique(['document_id', 'target']);
        });

        Schema::table('companies', function (Blueprint $table) {
            // Cifrada con APP_KEY (cast "encrypted").
            $table->text('holded_api_key')->nullable()->after('inbox_token');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('holded_api_key');
        });

        Schema::dropIfExists('document_exports');
    }
};
