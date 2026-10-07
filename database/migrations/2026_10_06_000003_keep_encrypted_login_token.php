<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Os avisos de WhatsApp levam o link pessoal de cada aprovadora (elas
 * esquecem o link e abrem em outros aparelhos). O hash continua sendo o que
 * vale para entrar; esta cópia, criptografada com a APP_KEY, só serve para
 * montar a mensagem.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('login_token_encrypted')->nullable()->after('login_token_hash');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('login_token_encrypted'));
    }
};
