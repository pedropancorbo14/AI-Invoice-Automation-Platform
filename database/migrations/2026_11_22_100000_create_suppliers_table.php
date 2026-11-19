<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Memoria por proveedor y por empresa cliente: cada empresa tiene su
        // propia cuenta contable y categoría para un mismo proveedor.
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('tax_id', 20);
            $table->string('name');
            $table->string('normalized_name');
            $table->string('ledger_account', 20)->nullable();
            $table->decimal('usual_vat_rate', 5, 2)->nullable();
            $table->string('category')->nullable();
            // Facturas suyas ya validadas: con 0, la siguiente va a revisión.
            $table->unsignedInteger('validated_count')->default(0);
            $table->timestamp('last_validated_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'tax_id']);
            $table->index(['account_id', 'tax_id']);
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->foreignId('supplier_id')->nullable()->after('inbound_email_id')->constrained()->nullOnDelete();
            $table->string('direction')->nullable()->after('status_reason');
            // CIF del proveedor + número + total: detecta la misma factura dos veces.
            $table->char('invoice_key', 64)->nullable()->after('sha256');

            $table->index(['account_id', 'invoice_key']);
        });

        Schema::table('accounts', function (Blueprint $table) {
            // Confianza mínima de los campos clave para validar sin revisión: high o medium.
            $table->string('min_confidence')->default('high')->after('inbox_token');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('min_confidence');
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex(['account_id', 'invoice_key']);
            $table->dropConstrainedForeignId('supplier_id');
            $table->dropColumn(['direction', 'invoice_key']);
        });

        Schema::dropIfExists('suppliers');
    }
};
