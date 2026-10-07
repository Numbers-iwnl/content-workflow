<?php

namespace App\Http\Controllers;

use App\Jobs\CacheDriveFile;
use App\Models\DriveFile;
use App\Models\Review;
use App\Models\ReviewAttachment;
use Illuminate\Support\Facades\Storage;

/**
 * Serve as cópias locais. response()->file() aceita requisições parciais
 * (Range), que o Safari exige para tocar vídeo.
 */
class MediaController extends Controller
{
    public function file(DriveFile $driveFile)
    {
        $disk = Storage::disk(config('conteudo.media_disk'));

        if (! $driveFile->hasFreshCache() || ! $disk->exists($driveFile->cache_path)) {
            CacheDriveFile::dispatch($driveFile->id);
            abort(404, 'Prévia ainda não está pronta.');
        }

        return response()->file($disk->path($driveFile->cache_path), [
            'Content-Type' => $driveFile->mime_type,
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    public function thumbnail(DriveFile $driveFile)
    {
        $disk = Storage::disk(config('conteudo.media_disk'));
        abort_unless($driveFile->thumbnail_path && $disk->exists($driveFile->thumbnail_path), 404);

        return response()->file($disk->path($driveFile->thumbnail_path), [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=604800',
        ]);
    }

    public function audio(Review $review)
    {
        abort_unless($review->audio_path, 404);

        return $this->private($review->audio_path);
    }

    public function attachment(ReviewAttachment $attachment)
    {
        return $this->private($attachment->path);
    }

    private function private(string $path)
    {
        abort_unless(Storage::exists($path), 404);

        return response()->file(Storage::path($path), ['Cache-Control' => 'private, max-age=86400']);
    }
}
