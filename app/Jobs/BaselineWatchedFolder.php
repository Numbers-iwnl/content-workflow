<?php

namespace App\Jobs;

use App\Models\WatchedFolder;
use App\Services\Drive\DriveSync;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Varredura inicial de uma pasta recém-cadastrada (pode levar minutos). */
class BaselineWatchedFolder implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public function __construct(public int $watchedFolderId) {}

    public function uniqueId(): string
    {
        return (string) $this->watchedFolderId;
    }

    public function handle(DriveSync $sync): void
    {
        $folder = WatchedFolder::find($this->watchedFolderId);

        if ($folder && ! $folder->baseline_completed_at) {
            $sync->baseline($folder);
        }
    }
}
