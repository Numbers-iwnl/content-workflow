<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Feedback da Ana (06/10):
 *  - Bruna e Carla aprovam de forma independente, sem ordem → some o "próxima revisora".
 *  - Depois da caixa de entrada, tudo vive na Planilha com um status simples
 *    (vazio, corrigido, agendado, postado); reprovados e ignorados viram "arquivado".
 *  - Tamanho do arquivo (dimensões, duração) na página do conteúdo.
 *  - Perfil da Diana.
 */
return new class extends Migration
{
    private const STATUS_MAP = [
        'em_aprovacao' => 'pendente',
        'aguardando_correcao' => 'pendente',
        'em_andamento' => 'pendente',
        'aprovado' => 'pendente',
        'reprovado' => 'arquivado',
        'ignorado' => 'arquivado',
    ];

    public function up(): void
    {
        foreach (self::STATUS_MAP as $old => $new) {
            DB::table('contents')->where('status', $old)->update(['status' => $new]);
        }

        Schema::table('contents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pending_reviewer_id');
        });

        Schema::table('drive_files', function (Blueprint $table) {
            $table->unsignedInteger('width')->nullable()->after('size');
            $table->unsignedInteger('height')->nullable()->after('width');
            $table->unsignedBigInteger('duration_ms')->nullable()->after('height');
        });

        if (! DB::table('users')->where('name', 'Diana')->exists()) {
            DB::table('users')->insert([
                'name' => 'Diana',
                'role' => 'admin',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('drive_files', function (Blueprint $table) {
            $table->dropColumn(['width', 'height', 'duration_ms']);
        });

        Schema::table('contents', function (Blueprint $table) {
            $table->foreignId('pending_reviewer_id')->nullable()->constrained('users')->nullOnDelete();
        });

        DB::table('contents')->where('status', 'pendente')->update(['status' => 'em_aprovacao']);
        DB::table('contents')->where('status', 'arquivado')->update(['status' => 'ignorado']);
    }
};
