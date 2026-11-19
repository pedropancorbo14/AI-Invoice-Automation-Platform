<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // Dirección de la cuenta: facturas+<token>@dominio. Lo que llega aquí
        // cae en la bandeja "sin asignar" de la cuenta.
        Schema::table('accounts', function (Blueprint $table) {
            $table->string('inbox_token', 32)->nullable()->unique()->after('name');
        });

        DB::table('accounts')->whereNull('inbox_token')->pluck('id')->each(
            fn (int $id) => DB::table('accounts')->where('id', $id)->update([
                'inbox_token' => Str::lower(Str::random(24)),
            ]),
        );

        Schema::table('accounts', function (Blueprint $table) {
            $table->string('inbox_token', 32)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropUnique(['inbox_token']);
            $table->dropColumn('inbox_token');
        });
    }
};
