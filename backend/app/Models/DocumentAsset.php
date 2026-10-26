<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class DocumentAsset extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['storage_path'];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }
}
