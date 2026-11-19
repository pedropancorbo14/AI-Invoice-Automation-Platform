<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            // Nulo = bandeja "sin asignar" (correo sin token válido, fase 2).
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('received');
            $table->text('status_reason')->nullable();
            $table->string('source');
            $table->string('disk');
            $table->string('path');
            $table->string('original_filename');
            $table->string('mime_type');
            $table->unsignedBigInteger('size');
            $table->char('sha256', 64);
            $table->timestamps();

            $table->index(['account_id', 'status']);
            $table->index(['account_id', 'sha256']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
