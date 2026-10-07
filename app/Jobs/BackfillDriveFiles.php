<?php

namespace App\Jobs;

use App\Models\Setting;
use App\Services\Drive\DriveSync;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;

/** "Trazer o que já estava nas pastas" (tela Pastas do Drive). */
class BackfillDriveFiles implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public function __construct(public string $since) {}

    public function handle(DriveSync $sync): void
    {
        $stats = $sync->backfill(Carbon::parse($this->since)->startOfDay());

        Setting::put('drive.last_backfill', json_encode([
            'since' => $this->since,
            'at' => now()->toIso8601String(),
            'imported' => $stats['imported'] ?? 0,
            'ignored' => $stats['ignored'] ?? 0,
        ]));
    }
}
