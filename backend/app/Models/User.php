<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $attributes = ['is_super_admin' => false, 'is_active' => true, 'role' => 'seo_executive'];

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'password' => 'hashed', 'is_super_admin' => 'boolean', 'is_active' => 'boolean', 'last_login_at' => 'datetime'];
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }
}
