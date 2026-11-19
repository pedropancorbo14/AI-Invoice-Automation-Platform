<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            // Por qué está en revisión (códigos de regla): para filtrar la bandeja.
            $table->json('review_codes')->nullable()->after('status_reason');
        });

        Schema::table('accounts', function (Blueprint $table) {
            // Resumen por email: daily, weekly u off.
            $table->string('digest_frequency')->default('daily')->after('min_confidence');
            $table->timestamp('last_digest_sent_at')->nullable()->after('digest_frequency');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn(['digest_frequency', 'last_digest_sent_at']);
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('review_codes');
        });
    }
};
