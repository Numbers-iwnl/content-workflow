<?php

namespace App\Jobs;

use App\Models\DriveFile;
use App\Services\Drive\DriveApi;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

/**
 * Baixa uma cópia do arquivo do Drive para o servidor, para a prévia abrir
 * no celular da Bruna e da Carla mesmo sem login no Google. Vídeos também
 * ganham a miniatura do Drive, usada como capa nas listas.
 */
class CacheDriveFile implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 900;

    public array $backoff = [60, 300];

    public function __construct(public int $driveFileId) {}

    public function uniqueId(): string
    {
        return (string) $this->driveFileId;
    }

    public function handle(DriveApi $drive): void
    {
        $file = DriveFile::with('content')->find($this->driveFileId);

        if (! $file || ! $file->content || ! $file->content->status->needsMedia()) {
            return;
        }

        $disk = Storage::disk(config('conteudo.media_disk'));
        $base = config('conteudo.media_dir')."/{$file->id}-".substr(md5($file->versionKey()), 0, 10);
        $disk->makeDirectory(config('conteudo.media_dir'));

        if (! $file->hasFreshCache()) {
            $relative = $base.'.'.(pathinfo($file->name, PATHINFO_EXTENSION) ?: 'bin');
            $drive->download($file->drive_file_id, $disk->path($relative));

            $this->replace($disk, $file->cache_path, $relative);
            $file->forceFill(['cache_path' => $relative, 'cached_md5' => $file->versionKey(), 'cached_at' => now()]);

            // Arquivo novo = miniatura velha.
            $this->replace($disk, $file->thumbnail_path, null);
            $file->thumbnail_path = null;
            $file->save();
        }

        if ($file->isVideo() && ! $file->thumbnail_path) {
            $thumbnail = "{$base}-thumb.jpg";

            if ($drive->downloadThumbnail($file->drive_file_id, $disk->path($thumbnail))) {
                $file->forceFill(['thumbnail_path' => $thumbnail])->save();
            } else {
                $disk->delete($thumbnail);
            }
        }
    }

    private function replace($disk, ?string $old, ?string $new): void
    {
        if ($old && $old !== $new) {
            $disk->delete($old);
        }
    }
}
