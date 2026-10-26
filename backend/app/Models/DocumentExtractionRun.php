<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class DocumentExtractionRun extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected $hidden = ['raw_json', 'raw_path'];

    protected function casts(): array
    {
        return ['warnings' => 'array', 'events' => 'array', 'statistics' => 'array', 'started_at' => 'datetime', 'completed_at' => 'datetime', 'heartbeat_at' => 'datetime', 'processed_units' => 'integer', 'total_units' => 'integer'];
    }

    public function document()
    {
        return $this->belongsTo(ReportDocument::class, 'document_id');
    }

    public function content()
    {
        return $this->hasMany(DocumentExtractedContent::class, 'extraction_run_id');
    }

    public function assets()
    {
        return $this->hasMany(DocumentAsset::class, 'extraction_run_id');
    }
}
