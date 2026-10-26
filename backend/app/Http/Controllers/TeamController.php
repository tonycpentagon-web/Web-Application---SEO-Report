<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\WorkspaceAccess;
use App\Services\WorkspaceView;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TeamController extends Controller
{
    public function __construct(public WorkspaceAccess $access, public WorkspaceView $view) {}

    private function authorizeAdmin(Request $r): void
    {
        abort_unless($this->access->administrator($r->user()), 403);
    }

    public function options(Request $r)
    {
        abort_if($this->access->role($r->user()) === 'client_viewer', 403);
        $r->validate(['page' => 'sometimes|integer|min:1', 'role' => 'nullable|in:super_admin,admin,seo_manager,seo_executive,client_viewer']);
        $q = $this->access->assignable($r->user());
        if ($r->input('role')) {
            $q->where('role', $r->input('role'));
        }

        return $q->orderBy('name')->orderBy('id')->paginate(100)->through(fn ($u) => $this->view->person($u));
    }

    public function index(Request $r)
    {
        $this->authorizeAdmin($r);
        $v = $r->validate(['search' => 'nullable|string|max:150', 'role' => 'nullable|in:super_admin,admin,seo_manager,seo_executive,client_viewer', 'page' => 'sometimes|integer|min:1']);
        $q = $this->access->users($r->user());
        if ($v['search'] ?? null) {
            $q->where(fn ($x) => $x->where('name', 'like', '%'.$v['search'].'%')->orWhere('email', 'like', '%'.$v['search'].'%'));
        }if ($v['role'] ?? null) {
            $q->where('role', $v['role']);
        }

        return $q->orderBy('name')->orderBy('id')->paginate(12)->through(fn ($u) => $this->details($r, $u));
    }

    public function show(Request $r, string $user)
    {
        $this->authorizeAdmin($r);
        $u = $this->access->users($r->user())->findOrFail($user);

        return response()->json(['data' => $this->details($r, $u)]);
    }

    private function details(Request $r, User $u): array
    {
        $visible = $this->access->projects($r->user())->where(function ($q) use ($u) {
            $q->where('user_id', $u->id)->orWhereHas('members', fn ($m) => $m->where('users.id', $u->id));
        })->get(['id', 'name']);

        return [...$this->view->person($u), 'created_at' => $u->created_at, 'projects' => $visible];
    }

    private function rules(Request $r, ?User $u = null): array
    {
        $roles = $this->access->super($r->user()) ? WorkspaceAccess::ROLES : ['seo_manager', 'seo_executive', 'client_viewer'];

        return ['name' => 'required|string|max:255', 'email' => ['required', 'email', 'max:254', Rule::unique('users')->ignore($u?->id)], 'role' => ['required', Rule::in($roles)], 'is_active' => 'sometimes|boolean'];
    }

    public function store(Request $r)
    {
        $this->authorizeAdmin($r);
        $r->merge(['email' => mb_strtolower(trim((string) $r->input('email')))]);
        $d = $r->validate($this->rules($r));
        $u = DB::transaction(function () use ($d, $r) {
            $u = new User;
            $u->forceFill([...$d, 'password' => Hash::make(Str::random(64)), 'is_super_admin' => $d['role'] === 'super_admin', 'managed_by' => $r->user()->id])->save();

            return $u;
        });
        $sent = $u->is_active ? $this->sendReset($u) : false;

        return response()->json(['data' => $this->details($r, $u), 'message' => $sent ? (config('mail.default') === 'log' ? 'Team member created. Password setup invitation generated in the private local mail log; external email delivery is not configured.' : 'Team member created. A secure password setup link was sent.') : 'Team member created. Password setup email was not sent; activate the account or retry password setup.', 'password_setup_sent' => $sent, 'invitation_delivery' => $sent ? (config('mail.default') === 'log' ? 'local_log' : 'mailer') : 'failed'], 201);
    }

    public function update(Request $r, string $user)
    {
        $this->authorizeAdmin($r);
        $u = $this->access->users($r->user())->findOrFail($user);
        $r->merge(['email' => mb_strtolower(trim((string) $r->input('email')))]);
        $d = $r->validate($this->rules($r, $u));
        abort_if(! $this->access->super($r->user()) && ($u->is_super_admin || $u->role === 'admin'), 403);
        abort_if($u->id === $r->user()->id && ($d['role'] !== $this->access->role($u) || isset($d['is_active']) && $d['is_active'] !== $u->is_active), 422, 'You cannot change your own role or active access here.');
        DB::transaction(function () use (&$u, $d) {
            $admins = User::where('is_super_admin', true)->where('is_active', true)->lockForUpdate()->get();
            $u = User::whereKey($u->id)->lockForUpdate()->firstOrFail();
            if ($u->is_super_admin && $u->is_active && ($d['role'] !== 'super_admin' || ($d['is_active'] ?? true) === false)) {
                abort_if($admins->count() <= 1, 422, 'Keep at least one active Super Admin.');
            }
            $revoke = $u->email !== $d['email'] || $u->role !== $d['role'] || isset($d['is_active']) && $u->is_active !== $d['is_active'];
            $previousEmail = $u->email;
            $u->forceFill([...$d, 'is_super_admin' => $d['role'] === 'super_admin'])->save();
            if ($revoke) {
                $u->forceFill(['remember_token' => Str::random(60)])->save();
                DB::table('sessions')->where('user_id', $u->id)->delete();
                DB::table('password_reset_tokens')->whereIn('email', [$previousEmail, $u->email])->delete();
            }
        });

        return response()->json(['data' => $this->details($r, $u), 'message' => 'Team member updated successfully.']);
    }

    public function resetPassword(Request $r, string $user)
    {
        $this->authorizeAdmin($r);
        $u = $this->access->users($r->user())->findOrFail($user);
        abort_if(! $this->access->super($r->user()) && ($u->is_super_admin || $u->role === 'admin'), 403);
        abort_unless($u->is_active, 409, 'Activate this account before sending password setup.');
        abort_unless($this->sendReset($u), 503, 'Password email could not be sent. Please retry later.');

        return response()->json(['message' => config('mail.default') === 'log' ? 'Password setup invitation generated in the private local mail log; external email delivery is not configured.' : 'A secure password setup link was sent.']);
    }

    private function sendReset(User $u): bool
    {
        try {
            return in_array(Password::sendResetLink(['email' => $u->email, 'is_active' => true]), [Password::RESET_LINK_SENT, Password::RESET_THROTTLED]);
        } catch (\Throwable $e) {
            Log::warning('Password setup delivery failed.', ['user_id' => $u->id]);

            return false;
        }
    }
}
