<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class ReportPdfExport extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected $hidden = ['payload', 'storage_path'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'sections' => 'array', 'generated_at' => 'datetime'];
    }
}
