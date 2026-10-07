<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['review_id', 'path', 'mime_type'])]
class ReviewAttachment extends Model {}
