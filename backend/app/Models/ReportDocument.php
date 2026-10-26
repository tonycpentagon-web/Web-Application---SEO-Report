<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ReportDocument extends Model
{
    use HasUlids,SoftDeletes, \App\Models\Concerns\HidesTrashedParents;

    protected $guarded = ['id'];

    protected $hidden = ['storage_path', 'stored_filename', 'active_checksum', 'deleted_by'];

    protected function casts(): array
    {
        return ['file_size' => 'integer'];
    }

    public function report()
    {
        return $this->belongsTo(ReportingPeriod::class, 'reporting_period_id');
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function runs()
    {
        return $this->hasMany(DocumentExtractionRun::class, 'document_id');
    }

    public function currentRun()
    {
        return $this->belongsTo(DocumentExtractionRun::class, 'current_run_id');
    }
}
