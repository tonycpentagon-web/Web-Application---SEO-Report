<?php

namespace App\Http\Controllers;

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => 'required|email|max:254', 'password' => 'required|string|max:1024']);
        $data['email'] = mb_strtolower(trim($data['email']));
        if (! Auth::guard('web')->attempt([...$data, 'is_active' => true])) {
            // Do not log submitted credentials or email addresses.
            Log::notice('Authentication attempt rejected.', ['ip' => $request->ip()]);
            throw ValidationException::withMessages(['email' => ['The email or password is incorrect.']]);
        }
        $request->session()->regenerate();
        $request->user()->forceFill(['last_login_at' => now()])->save();

        return response()->json(['user' => $request->user()]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Signed out.']);
    }

    public function forgot(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => 'required|email|max:254']);
        Password::sendResetLink(['email' => mb_strtolower(trim($data['email'])), 'is_active' => true]);

        return response()->json(['message' => 'If an account exists, a password reset link has been sent.']);
    }

    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => 'required|string|max:255', 'email' => 'required|email|max:254',
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);
        $status = Password::reset([...$data, 'email' => mb_strtolower(trim($data['email'])), 'is_active' => true], function ($user, $password) {
            DB::transaction(function () use ($user, $password) {
                $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
                DB::table('sessions')->where('user_id', $user->id)->delete();
            });
            event(new PasswordReset($user));
        });
        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => [__($status)]]);
        }

        return response()->json(['message' => 'Password reset. Sign in with your new password.']);
    }

    public function password(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => 'required|current_password:web',
            'password' => ['required', 'confirmed', 'different:current_password', PasswordRule::defaults()],
        ]);
        DB::transaction(function () use ($request, $data) {
            $request->user()->forceFill(['password' => Hash::make($data['password']), 'remember_token' => Str::random(60)])->save();
            DB::table('sessions')->where('user_id', $request->user()->id)->delete();
        });

        return $this->logout($request);
    }
}
