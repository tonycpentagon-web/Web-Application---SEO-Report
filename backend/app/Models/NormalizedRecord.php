<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class NormalizedRecord extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected $appends = ['data'];

    protected function casts(): array
    {
        return ['source_data' => 'array', 'raw_value' => 'array', 'manual_override' => 'array', 'revision' => 'integer', 'active' => 'boolean', 'reviewed_at' => 'datetime'];
    }

    public function getDataAttribute(): array
    {
        return array_replace($this->source_data ?? [], $this->manual_override ?? []);
    }

    public function sources()
    {
        return $this->hasMany(MetricSourceReference::class);
    }

    public function corrections()
    {
        return $this->hasMany(RecordCorrection::class);
    }
}
