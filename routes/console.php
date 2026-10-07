<?php

use App\Enums\Account;
use App\Enums\ContentStatus;
use App\Enums\DriveFileState;
use App\Models\DriveFile;
use App\Models\User;
use App\Models\WatchedFolder;
use App\Services\Drive\ContentRules;
use App\Services\Drive\DriveApi;
use App\Services\Drive\DriveSync;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

Artisan::command('drive:watch {folder : Link ou ID da pasta no Drive} {--account= : principal, marca_b ou podcast}', function (DriveSync $sync) {
    $account = $this->option('account') ? Account::from($this->option('account')) : null;

    try {
        $watched = $sync->register($this->argument('folder'), $account);
    } catch (InvalidArgumentException $e) {
        $this->error($e->getMessage());

        return 1;
    }

    if ($watched->baseline_completed_at) {
        $this->info("\"{$watched->name}\" já está sendo monitorada.");

        return 0;
    }

    $this->info("Registrando o que já existe em \"{$watched->name}\" (não vira conteúdo)...");
    $stats = $sync->baseline($watched);
    $this->info('Pronto: '.($stats['baseline'] ?? 0).' arquivos existentes. Daqui pra frente, tudo que entrar vira conteúdo.');
})->purpose('Começa a monitorar uma pasta do Drive');

Artisan::command('drive:folders', function () {
    $this->table(
        ['#', 'Pasta', 'Conta padrão', 'Ativa', 'Última varredura'],
        WatchedFolder::all()->map(fn ($f) => [
            $f->id, $f->name, $f->default_account?->label() ?? '—', $f->active ? 'sim' : 'não', $f->last_reconciled_at?->diffForHumans() ?? '—',
        ]),
    );
})->purpose('Lista as pastas monitoradas');

Artisan::command('drive:sync', function (DriveSync $sync) {
    $stats = $sync->syncChanges();
    $this->line(json_encode($stats ?: ['sem mudanças' => 0], JSON_UNESCAPED_UNICODE));
})->purpose('Processa o que mudou no Drive desde a última vez');

Artisan::command('drive:reconcile', function (DriveSync $sync) {
    foreach (WatchedFolder::where('active', true)->whereNotNull('baseline_completed_at')->get() as $folder) {
        $stats = $sync->reconcile($folder);
        $this->line("{$folder->name}: ".json_encode($stats, JSON_UNESCAPED_UNICODE));
    }
})->purpose('Varre as pastas inteiras para pegar qualquer mudança perdida');

Artisan::command('media:purge', function () {
    $disk = Storage::disk(config('conteudo.media_disk'));
    $cutoff = now()->subDays(config('conteudo.purge_media_after_days'));

    $files = DriveFile::whereNotNull('cache_path')
        ->whereHas('content', fn ($q) => $q
            ->whereIn('status', [ContentStatus::Postado, ContentStatus::Arquivado])
            ->where('updated_at', '<', $cutoff))
        ->get();

    foreach ($files as $file) {
        $disk->delete(array_filter([$file->cache_path, $file->thumbnail_path]));
        $file->forceFill(['cache_path' => null, 'cached_md5' => null, 'cached_at' => null, 'thumbnail_path' => null])->save();
    }

    $this->info("{$files->count()} cópias apagadas.");
})->purpose('Apaga as cópias de vídeo/imagem de conteúdos já encerrados');

Artisan::command('users:link {name : Nome da pessoa}', function () {
    $user = User::where('name', $this->argument('name'))->first();

    if (! $user) {
        $this->error('Pessoa não encontrada. Cadastradas: '.User::pluck('name')->join(', '));

        return 1;
    }

    $this->info("Link de {$user->name} (o anterior deixa de funcionar):");
    $this->line(route('login.link', $user->issueLoginToken()));
})->purpose('Gera o link pessoal de acesso de alguém');

Artisan::command('demo:import {count=8}', function (DriveApi $drive, DriveSync $sync) {
    if (! app()->isLocal()) {
        $this->error('Só em ambiente local.');

        return 1;
    }

    // Reimporta arquivos reais recentes como se tivessem acabado de chegar,
    // para testar as telas com prévias de verdade.
    $files = DriveFile::where('state', DriveFileState::Baseline)
        ->where('path', 'like', '%2026%')
        ->where(fn ($q) => $q->where('mime_type', 'like', 'video/%')->orWhere('mime_type', 'like', 'image/%'))
        ->where('size', '<', 80 * 1024 * 1024)
        ->latest('drive_created_at')
        ->take(300)
        ->get()
        ->filter(fn ($f) => ! app(ContentRules::class)->ignoreReason(['name' => $f->name, 'mimeType' => $f->mime_type], explode(' / ', $f->path)))
        ->groupBy(fn ($f) => str_starts_with($f->mime_type, 'video/') ? 'video' : 'image')
        ->flatMap(fn ($group) => $group->take((int) ceil($this->argument('count') / 2)));

    foreach ($files as $file) {
        $file->delete();
        $sync->handleFile($drive->getFile($file->drive_file_id));
    }

    $this->info("{$files->count()} arquivos reimportados. Rode php artisan queue:work para baixar as prévias.");
})->purpose('(local) Traz arquivos reais para a caixa de entrada, para testar');

// Na Hostinger, um único cron por minuto: php artisan schedule:run
// Atualizações sem SSH: depois de subir o zip novo, as migrações rodam sozinhas no próximo minuto.
Schedule::command('migrate --force')->everyMinute()->withoutOverlapping(10);
Schedule::command('drive:sync')->everyFiveMinutes()->withoutOverlapping(10);
Schedule::command('drive:reconcile')->dailyAt('03:00')->withoutOverlapping(60);
Schedule::command('media:purge')->dailyAt('04:00');
Schedule::command('queue:work --stop-when-empty --max-time=270 --tries=3')->everyMinute()->withoutOverlapping(10)->runInBackground();
