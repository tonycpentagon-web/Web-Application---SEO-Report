<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    use HasUlids, \Illuminate\Database\Eloquent\SoftDeletes;

    protected $fillable = ['name', 'company_name', 'website', 'contact_email', 'contact_phone', 'country', 'brand_color', 'status', 'notes'];

    protected $hidden = ['user_id', 'identity_key', 'logo_path'];

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function admins(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'client_memberships')->withTimestamps();
    }
}
