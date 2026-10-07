<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['id', 'name', 'parent_id'])]
class DriveFolder extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';
}
