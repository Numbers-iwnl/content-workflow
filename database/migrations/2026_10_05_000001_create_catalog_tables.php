<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pastas do Drive que o sistema acompanha (ex.: VÍDEOS PARA CONFERÊNCIA).
        Schema::create('watched_folders', function (Blueprint $table) {
            $table->id();
            $table->string('drive_folder_id')->unique();
            $table->string('name');
            $table->string('default_account')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamp('baseline_completed_at')->nullable();
            $table->timestamp('last_reconciled_at')->nullable();
            $table->timestamps();
        });

        // Cache das pastas do Drive, para montar o caminho de cada arquivo
        // sem consultar a API toda vez.
        Schema::create('drive_folders', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->string('parent_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('contents', function (Blueprint $table) {
            $table->id();
            $table->string('status')->default('novo')->index();
            $table->string('title');
            $table->string('account')->nullable();
            $table->json('collab_accounts')->nullable();
            $table->string('type')->nullable();
            $table->string('cta')->nullable();
            $table->string('project')->nullable();
            $table->text('caption')->nullable();
            $table->string('produced_by')->nullable();
            $table->foreignId('watched_folder_id')->nullable()->constrained()->nullOnDelete();
            // Carrossel: a subpasta do Drive que agrupa as imagens.
            $table->string('group_folder_id')->nullable()->index();
            $table->string('drive_path')->nullable();
            $table->timestamp('drive_created_at')->nullable();
            $table->foreignId('pending_reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('round')->default(1);
            // Arquivo novo que parece ser a correção de outro conteúdo.
            $table->foreignId('suggested_correction_of_id')->nullable()->constrained('contents')->nullOnDelete();
            $table->timestamp('sent_for_review_at')->nullable();
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('drive_files', function (Blueprint $table) {
            $table->id();
            $table->string('drive_file_id')->unique();
            $table->foreignId('content_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('watched_folder_id')->nullable()->constrained()->nullOnDelete();
            $table->string('parent_id')->nullable()->index();
            $table->string('state');
            $table->string('ignore_reason')->nullable();
            $table->string('name');
            $table->string('path')->nullable();
            $table->string('mime_type');
            $table->unsignedBigInteger('size')->nullable();
            $table->string('md5')->nullable();
            $table->string('head_revision_id')->nullable();
            $table->string('web_view_link')->nullable();
            $table->string('owner_name')->nullable();
            $table->string('owner_email')->nullable();
            $table->timestamp('drive_created_at')->nullable();
            $table->timestamp('drive_modified_at')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            // Cópia local para a prévia (vídeos abrem sem login no Google).
            $table->string('cache_path')->nullable();
            $table->string('cached_md5')->nullable();
            $table->timestamp('cached_at')->nullable();
            $table->timestamps();
        });

        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->unsignedSmallInteger('round');
            $table->string('decision');
            $table->text('note')->nullable();
            $table->string('audio_path')->nullable();
            $table->timestamps();
        });

        Schema::create('review_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('mime_type')->nullable();
            $table->timestamps();
        });

        // Histórico: quem fez o quê e quando.
        Schema::create('content_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->json('data')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
        Schema::dropIfExists('content_events');
        Schema::dropIfExists('review_attachments');
        Schema::dropIfExists('reviews');
        Schema::dropIfExists('drive_files');
        Schema::dropIfExists('contents');
        Schema::dropIfExists('drive_folders');
        Schema::dropIfExists('watched_folders');
    }
};
