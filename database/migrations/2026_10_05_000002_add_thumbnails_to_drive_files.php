<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drive_files', function (Blueprint $table) {
            // Miniatura gerada pelo Drive (vídeos ganham "capa" na lista).
            $table->string('thumbnail_path')->nullable()->after('cached_at');
        });
    }

    public function down(): void
    {
        Schema::table('drive_files', fn (Blueprint $table) => $table->dropColumn('thumbnail_path'));
    }
};
