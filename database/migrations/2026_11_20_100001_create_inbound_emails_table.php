<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cada correo recibido en el buzón, con su original (.eml) para la auditoría.
        Schema::create('inbound_emails', function (Blueprint $table) {
            $table->id();
            // Nulos mientras no se sabe a quién va (o si no lleva un token válido).
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('pending');
            $table->text('status_reason')->nullable();
            // Hash del Message-ID (o del contenido si no lo trae): evita procesar dos veces.
            $table->char('fingerprint', 64)->unique();
            $table->text('message_id')->nullable();
            $table->string('from_address')->nullable();
            $table->string('from_name')->nullable();
            $table->string('recipient')->nullable();
            $table->text('subject')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('disk');
            $table->string('raw_path');
            $table->unsignedBigInteger('size');
            // Qué pasó con cada adjunto: guardado, duplicado, descartado...
            $table->json('summary')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'status']);
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->foreignId('inbound_email_id')->nullable()->after('company_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('inbound_email_id');
        });

        Schema::dropIfExists('inbound_emails');
    }
};
