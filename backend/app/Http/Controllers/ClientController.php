<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveClientRequest;
use App\Models\Client;
use App\Models\User;
use App\Services\WorkspaceAccess;
use App\Services\WorkspaceView;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ClientController extends Controller
{
    public function __construct(public WorkspaceAccess $access, public WorkspaceView $view) {}

    private function find(Request $r, string $client): Client
    {
        return $this->access->clients($r->user())->findOrFail($client);
    }

    public function index(Request $r)
    {
        $v = $r->validate(['search' => 'nullable|string|max:150', 'status' => 'nullable|in:active,archived', 'page' => 'sometimes|integer|min:1']);
        $q = $this->access->clients($r->user());
        if ($v['search'] ?? null) {
            $q->where(fn ($x) => $x->where('name', 'like', '%'.$v['search'].'%')->orWhere('company_name', 'like', '%'.$v['search'].'%'));
        }
        if ($v['status'] ?? null) {
            $q->where('status', $v['status']);
        }

        return $q->orderBy('name')->orderBy('id')->paginate(12)->through(fn ($c) => $this->view->client($r->user(), $c));
    }

    public function store(SaveClientRequest $r)
    {
        abort_unless($this->access->administrator($r->user()), 403);
        $v = $r->validated();
        $key = hash('sha256', mb_strtolower(trim($v['name'])));
        if (Client::where('user_id', $r->user()->id)->where('identity_key', $key)->exists()) {
            throw ValidationException::withMessages(['name' => 'A client with this name already exists in your workspace.']);
        }
        $c = new Client($v);
        $c->user_id = $r->user()->id;
        $c->identity_key = $key;
        $c->save();

        return response()->json(['data' => $this->view->client($r->user(), $c), 'message' => 'Client created successfully.'], 201);
    }

    public function show(Request $r, string $client)
    {
        $c = $this->find($r, $client);
        $data = $this->view->client($r->user(), $c);
        $data['projects'] = $this->access->projects($r->user())->where('client_id', $c->id)->latest()->get()->map(fn ($p) => $this->view->project($r->user(), $p));
        if ($this->access->super($r->user())) {
            $data['assigned_admin_ids'] = $c->admins()->pluck('users.id');
        }

        return response()->json(['data' => $data]);
    }

    public function update(SaveClientRequest $r, string $client)
    {
        $c = $this->find($r, $client);
        abort_unless($this->access->manageClient($r->user(), $c), 403);
        $v = $r->validated();
        $key = hash('sha256', mb_strtolower(trim($v['name'])));
        if (Client::where('user_id', $c->user_id)->where('identity_key', $key)->where('id', '!=', $c->id)->exists()) {
            throw ValidationException::withMessages(['name' => 'A client with this name already exists.']);
        }
        DB::transaction(function () use ($r, &$c, $v, $key) {
            $c=Client::whereKey($c->id)->lockForUpdate()->firstOrFail();
            $before=$c->status;
            if($before!==($v['status']??$before)){
                app(\App\Services\DraftInvalidation::class)->assertIdle(\App\Models\ReportingPeriod::withoutGlobalScopes()->whereIn('project_id',\App\Models\Project::withoutGlobalScopes()->where('client_id',$c->id)->select('id'))->pluck('id')->all());
                app(\App\Services\WorkspaceLifecycle::class)->audit($r->user(),'client',$c->id,'status_changed',['before'=>$before,'after'=>$v['status']]);
            }
            $c->fill($v);
            $c->identity_key = $key;
            $c->save();
        });

        return response()->json(['data' => $this->view->client($r->user(), $c->fresh()), 'message' => 'Client updated successfully.']);
    }

    public function logo(Request $r, string $client)
    {
        $c = $this->find($r, $client);
        abort_unless($c->logo_path && Storage::disk('local')->exists($c->logo_path), 404);

        return response()->file(Storage::disk('local')->path($c->logo_path), ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    public function uploadLogo(Request $r, string $client)
    {
        $c = $this->find($r, $client);
        abort_unless($this->access->manageClient($r->user(), $c), 403);
        $r->validate(['logo' => 'required|image|mimes:jpg,jpeg,png,webp|max:2048|dimensions:max_width=2048,max_height=2048']);
        $path = $r->file('logo')->store('client-logos/'.$c->id, 'local');
        $old = $c->logo_path;
        try {
            DB::transaction(fn () => $c->forceFill(['logo_path' => $path])->save());
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
        if ($old) {
            Storage::disk('local')->delete($old);
        }

        return response()->json(['data' => $this->view->client($r->user(), $c), 'message' => 'Client logo updated successfully.']);
    }

    public function admins(Request $r, string $client)
    {
        abort_unless($this->access->super($r->user()), 403);
        $c = Client::findOrFail($client);
        $v = $r->validate(['user_ids' => 'present|array|max:100', 'user_ids.*' => 'required|integer|distinct']);
        $ids = $v['user_ids'];
        abort_unless(User::whereIn('id', $ids)->where('role', 'admin')->where('is_active', true)->count() === count($ids), 422, 'Only active Admin accounts can manage organizational clients.');
        DB::transaction(fn () => $c->admins()->sync($ids));

        return response()->json(['message' => 'Client administrators updated successfully.']);
    }
}
