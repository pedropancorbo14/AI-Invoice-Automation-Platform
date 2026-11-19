<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cada extracción con IA: qué devolvió, con qué modelo, cuánto costó y cuánto tardó.
        Schema::create('document_extractions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->string('model');
            $table->string('input_mode');
            $table->string('document_type');
            $table->json('data');
            $table->json('confidence');
            $table->json('notes');
            $table->unsignedInteger('input_tokens');
            $table->unsignedInteger('output_tokens');
            $table->unsignedInteger('cache_write_tokens')->default(0);
            $table->unsignedInteger('cache_read_tokens')->default(0);
            $table->decimal('cost_usd', 10, 6);
            $table->unsignedInteger('duration_ms');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['account_id', 'created_at']);
            $table->index(['document_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_extractions');
    }
};
