<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Auditoría inmutable: quién, qué y cuándo para cada documento.
        Schema::create('document_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            // Nulo = lo hizo el sistema (un job), no una persona.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->text('reason')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['document_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_events');
    }
};
