<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class ReportPublication extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'version' => 'integer', 'published_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Publication snapshots are immutable.'));
        static::deleting(fn () => throw new \LogicException('Publication history is retained.'));
    }
}
