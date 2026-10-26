<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class NormalizationRun extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected $hidden = ['input_snapshot'];

    protected function casts(): array
    {
        return ['input_snapshot' => 'array', 'summary' => 'array', 'started_at' => 'datetime', 'completed_at' => 'datetime'];
    }
}
