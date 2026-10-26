<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Project;
use App\Models\ReportingPeriod;
use App\Models\User;

class WorkspaceView
{
    public function __construct(public WorkspaceAccess $access) {}

    public function person(User $u): array
    {
        return ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'role' => $this->access->role($u), 'is_active' => $u->is_active, 'is_super_admin' => $u->is_super_admin];
    }

    public function client(User $u, Client $c): array
    {
        $projects = $this->access->projects($u)->where('client_id', $c->id);
        $reports = ReportingPeriod::whereIn('project_id', (clone $projects)->select('projects.id'));
        if ($this->access->role($u) === 'client_viewer') {
            $reports->where('status', 'published')->whereHas('viewers', fn ($v) => $v->where('users.id', $u->id)->where('report_shares.expires_at', '>', now()));
        }
        $data = $c->toArray();
        if ($this->access->role($u) === 'client_viewer') {
            $data = array_intersect_key($data, array_flip(['id', 'name', 'website', 'brand_color', 'status', 'created_at', 'updated_at']));
        }

        return [...$data, 'has_logo' => (bool) $c->logo_path, 'projects_count' => (clone $projects)->count(), 'reporting_periods_count' => $reports->count(),
            'permissions' => ['manage' => $this->access->manageClient($u, $c), 'assign_admins' => $this->access->super($u)]];
    }

    public function project(User $u, Project $p, bool $details = false): array
    {
        $data = $p->toArray();
        $data['client_name'] = $p->client?->name ?? $p->client_name;
        $data['client'] = $p->client ? $this->client($u, $p->client) : null;
        $data['permissions'] = $this->access->projectPermissions($u, $p);
        $data['reporting_periods_count'] = $this->access->reports($u, $p)->count();
        if ($this->access->role($u) === 'client_viewer') {
            $data['description'] = null;
        }
        if ($details) {
            $data['members'] = $this->access->role($u) === 'client_viewer' ? [] : $p->members()->get()->map(fn ($m) => [...$this->person($m), 'assignment_role' => $m->pivot->assignment_role])->all();
            $data['latest_report'] = $this->access->reports($u, $p)->orderByDesc('end_date')->first();
            $data['recent_activity'] = $this->access->role($u) === 'client_viewer' ? [] : $p->activities()->with('actor:id,name')->latest()->orderByDesc('id')->limit(8)->get()->toArray();
        }

        return $data;
    }
}
