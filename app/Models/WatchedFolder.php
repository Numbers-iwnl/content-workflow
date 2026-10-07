<?php

namespace App\Models;

use App\Enums\Account;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['drive_folder_id', 'name', 'default_account', 'active'])]
class WatchedFolder extends Model
{
    protected function casts(): array
    {
        return [
            'default_account' => Account::class,
            'active' => 'boolean',
            'baseline_completed_at' => 'datetime',
            'last_reconciled_at' => 'datetime',
        ];
    }

    public function files(): HasMany
    {
        return $this->hasMany(DriveFile::class);
    }
}
