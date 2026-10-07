<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Feedback da Ana (06/10, 13h32):
 *  - Só vai para a Bruna e a Carla o que a Ana escolher (vídeo de tráfego,
 *    por exemplo, não precisa de aprovação).
 *  - Um conteúdo reprovado pode voltar ao zero: as opiniões anteriores ficam
 *    no histórico, mas deixam de contar a partir de reviews_reset_after_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->boolean('needs_approval')->default(false)->after('status');
            $table->unsignedBigInteger('reviews_reset_after_id')->nullable()->after('round');
        });

        // O que já recebeu alguma opinião continua na aprovação; o resto a Ana escolhe.
        DB::table('contents')
            ->whereIn('id', DB::table('reviews')->select('content_id'))
            ->update(['needs_approval' => true]);
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->dropColumn(['needs_approval', 'reviews_reset_after_id']);
        });
    }
};
