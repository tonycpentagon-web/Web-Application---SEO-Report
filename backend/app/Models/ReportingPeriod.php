<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ReportingPeriod extends Model
{
    use HasUlids, \Illuminate\Database\Eloquent\SoftDeletes, \App\Models\Concerns\HidesTrashedParents;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d', 'workspace_archived_at'=>'datetime', 'requires_regeneration'=>'boolean', 'version' => 'integer'];
    }

    public function documents()
    {
        return $this->hasMany(ReportDocument::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function viewers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'report_shares', 'reporting_period_id', 'user_id')->withPivot('shared_by', 'expires_at', 'allow_download')->withTimestamps();
    }
}
