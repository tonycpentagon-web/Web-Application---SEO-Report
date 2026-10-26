<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class WorkspaceAccess
{
    public const ROLES = ['super_admin', 'admin', 'seo_manager', 'seo_executive', 'client_viewer'];

    public function role(User $u): string
    {
        return $u->is_super_admin ? 'super_admin' : $u->role;
    }

    public function super(User $u): bool
    {
        return $u->is_active && $u->is_super_admin;
    }

    public function administrator(User $u): bool
    {
        return $this->super($u) || $this->role($u) === 'admin';
    }

    public function clients(User $u): Builder
    {
        $q = Client::query();
        if ($this->super($u)) {
            return $q;
        }

        return $q->where(function ($q) use ($u) {
            if ($this->role($u) !== 'client_viewer') {
                $q->where('user_id', $u->id)->orWhereHas('projects', fn ($x) => $x->whereIn('projects.id', $this->projects($u)->select('projects.id')));
                if ($this->role($u) === 'admin') {
                    $q->orWhereHas('admins', fn ($x) => $x->where('users.id', $u->id));
                }
            } else {
                $q->whereHas('projects', fn ($x) => $x->whereIn('projects.id', $this->projects($u)->select('projects.id')));
            }
        });
    }

    public function projects(User $u): Builder
    {
        $q = Project::query();
        if ($this->super($u)) {
            return $q;
        }
        if ($this->role($u) === 'client_viewer') {
            return $q->whereHas('reports', fn ($x) => $x->where('status', 'published')->whereHas('viewers', fn ($v) => $v->where('users.id', $u->id)->where('report_shares.expires_at', '>', now())));
        }

        return $q->where(function ($q) use ($u) {
            $q->where('user_id', $u->id)->orWhereHas('members', fn ($m) => $m->where('users.id', $u->id));
            if ($this->role($u) === 'admin') {
                $q->orWhereHas('client', fn ($c) => $c->where('user_id', $u->id)->orWhereHas('admins', fn ($a) => $a->where('users.id', $u->id)));
            }
        });
    }

    public function manageClient(User $u, Client $c): bool
    {
        return $this->super($u) || ($this->role($u) === 'admin' && ($c->user_id === $u->id || $c->admins()->where('users.id', $u->id)->exists()));
    }

    public function manageProject(User $u, Project $p): bool
    {
        if ($this->super($u)) {
            return true;
        }
        if ($this->role($u) === 'client_viewer') {
            return false;
        }
        if ($this->role($u) === 'admin' && $p->client && $this->manageClient($u, $p->client)) {
            return true;
        }

        return (in_array($this->role($u), ['admin', 'seo_manager']) && $p->user_id === $u->id) || ($this->role($u) === 'seo_manager' && $p->members()->where('users.id', $u->id)->wherePivot('assignment_role', 'manager')->exists());
    }

    public function workReports(User $u, Project $p): bool
    {
        return $this->manageProject($u, $p) || ($this->role($u) === 'seo_executive' && ($p->user_id === $u->id || $p->members()->where('users.id', $u->id)->exists()));
    }

    public function reports(User $u, Project $p): Builder
    {
        $q = $p->reports();
        if ($this->role($u) === 'client_viewer') {
            $q->where('status', 'published')->whereHas('viewers', fn ($v) => $v->where('users.id', $u->id)->where('report_shares.expires_at', '>', now()));
        }

        return $q->getQuery();
    }

    public function users(User $u): Builder
    {
        if ($this->super($u)) {
            return User::query();
        }
        if ($this->role($u) === 'admin') {
            return User::where('managed_by', $u->id)->where('is_super_admin', false)->whereNotIn('role', ['super_admin', 'admin']);
        }

        return User::whereRaw('1=0');
    }

    public function assignable(User $u): Builder
    {
        if ($this->super($u)) {
            return User::where('is_active', true);
        }
        $owner = $this->role($u) === 'admin' ? $u->id : $u->managed_by;

        return User::where('is_active', true)->where('is_super_admin', false)->whereIn('role', ['admin', 'seo_manager', 'seo_executive', 'client_viewer'])->where(function ($q) use ($owner, $u) {
            $q->where('id', $u->id);
            if ($owner) {
                $q->orWhere('managed_by', $owner);
            }
        });
    }

    public function projectPermissions(User $u, Project $p): array
    {
        return ['manage' => $this->manageProject($u,$p), 'work_reports' => $this->workReports($u,$p), 'view_internal' => $this->role($u) !== 'client_viewer'];
    }
}
