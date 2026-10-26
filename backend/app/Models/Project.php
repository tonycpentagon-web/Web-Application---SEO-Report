<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use HasFactory,HasUlids, \Illuminate\Database\Eloquent\SoftDeletes, \App\Models\Concerns\HidesTrashedParents;

    protected $fillable = ['name', 'client_name', 'website', 'description', 'status', 'reporting_frequency'];

    protected $attributes = ['reporting_frequency' => 'monthly'];

    protected $hidden = ['user_id'];

    protected function casts(): array
    {
        return ['start_date' => 'date:Y-m-d'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_memberships')->withPivot('assignment_role')->withTimestamps();
    }

    public function reports(): HasMany
    {
        return $this->hasMany(ReportingPeriod::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(ProjectActivityLog::class);
    }
}
