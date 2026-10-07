<?php

namespace App\Models;

use App\Enums\DriveFileState;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Guarded(['id'])]
class DriveFile extends Model
{
    protected function casts(): array
    {
        return [
            'state' => DriveFileState::class,
            'drive_created_at' => 'datetime',
            'drive_modified_at' => 'datetime',
            'cached_at' => 'datetime',
        ];
    }

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    public function watchedFolder(): BelongsTo
    {
        return $this->belongsTo(WatchedFolder::class);
    }

    public function isVideo(): bool
    {
        return str_starts_with($this->mime_type, 'video/');
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    /** Identifica a versão atual do arquivo (muda quando sobem uma correção). */
    public function versionKey(): string
    {
        return $this->md5 ?? $this->head_revision_id ?? (string) $this->drive_modified_at?->timestamp;
    }

    public function hasFreshCache(): bool
    {
        return $this->cache_path !== null && $this->cached_md5 === $this->versionKey();
    }

    /** "1080 × 1920 · 1:11 · 136 MB" (o que se sabe do arquivo). */
    public function specs(): string
    {
        $parts = [];

        if ($this->width && $this->height) {
            $parts[] = "{$this->width} × {$this->height}";
        }

        if ($this->duration_ms) {
            $seconds = (int) round($this->duration_ms / 1000);
            $parts[] = intdiv($seconds, 60).':'.str_pad($seconds % 60, 2, '0', STR_PAD_LEFT);
        }

        if ($this->size) {
            $parts[] = $this->size >= 1048576
                ? number_format($this->size / 1048576, 1, ',', '.').' MB'
                : number_format($this->size / 1024, 0, ',', '.').' KB';
        }

        return implode(' · ', $parts);
    }

    /** Falta a cópia local ou (em vídeo) a miniatura. */
    public function needsCaching(): bool
    {
        return ! $this->hasFreshCache() || ($this->isVideo() && ! $this->thumbnail_path);
    }

    /** URL da imagem de capa: a própria imagem, ou a miniatura do vídeo. */
    public function posterUrl(): ?string
    {
        return match (true) {
            $this->isImage() && $this->hasFreshCache() => route('media.file', $this),
            $this->isVideo() && $this->thumbnail_path !== null => route('media.thumbnail', $this),
            default => null,
        };
    }
}
