<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_read_private_endpoints(): void
    {
        foreach (['/api/user', '/api/dashboard', '/api/projects'] as $url) {
            $this->getJson($url)->assertUnauthorized();
        }
    }

    public function test_login_logout_and_user_identity(): void
    {
        $user = User::factory()->create(['password' => 'Initial!Password123']);
        $this->postJson('/login', ['email' => $user->email, 'password' => 'wrong'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertGuest();
        $this->postJson('/login', ['email' => $user->email, 'password' => 'Initial!Password123'])->assertOk()->assertJsonPath('user.id', $user->id)->assertJsonMissingPath('user.password');
        $this->getJson('/api/user')->assertOk()->assertJsonPath('email', $user->email);
        $this->postJson('/logout')->assertOk();
        $this->assertGuest();
        // Simulate a new HTTP request: discard the cached Sanctum RequestGuard.
        auth()->forgetGuards();
        $this->getJson('/api/user')->assertUnauthorized();
    }

    public function test_login_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/login', ['email' => 'throttle@example.test', 'password' => 'bad'])->assertUnprocessable();
        }
        $this->postJson('/login', ['email' => 'throttle@example.test', 'password' => 'bad'])->assertStatus(429);
    }

    public function test_password_change_requires_current_password_and_strong_confirmation(): void
    {
        $user = User::factory()->create(['password' => 'Initial!Password123']);
        $this->actingAs($user);
        $this->putJson('/password', ['current_password' => 'wrong', 'password' => 'Next!Password123', 'password_confirmation' => 'Next!Password123'])->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->putJson('/password', ['current_password' => 'Initial!Password123', 'password' => 'weak', 'password_confirmation' => 'different'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertTrue(Hash::check('Initial!Password123', $user->fresh()->password));
    }

    public function test_password_change_revokes_database_sessions_and_signs_out(): void
    {
        $user = User::factory()->create(['password' => 'Initial!Password123']);
        DB::table('sessions')->insert(['id' => 'another-device', 'user_id' => $user->id, 'payload' => 'test', 'last_activity' => time()]);
        $this->actingAs($user)->putJson('/password', ['current_password' => 'Initial!Password123', 'password' => 'Next!Password123', 'password_confirmation' => 'Next!Password123'])->assertOk();
        $this->assertGuest();
        $this->assertTrue(Hash::check('Next!Password123', $user->fresh()->password));
        $this->assertDatabaseMissing('sessions', ['id' => 'another-device']);
    }

    public function test_forgot_password_is_generic_and_notification_points_to_frontend(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $known = $this->postJson('/forgot-password', ['email' => $user->email])->assertOk()->json('message');
        $unknown = $this->postJson('/forgot-password', ['email' => 'unknown@example.test'])->assertOk()->json('message');
        $this->assertSame($known, $unknown);
        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $url = $notification->toMail($user)->actionUrl;

            return str_starts_with($url, config('insight.frontend_url').'/reset-password?') && str_contains($url, urlencode($user->email));
        });
    }

    public function test_reset_token_is_single_use_and_revokes_sessions(): void
    {
        $user = User::factory()->create(['password' => 'Initial!Password123']);
        DB::table('sessions')->insert(['id' => 'old-device', 'user_id' => $user->id, 'payload' => 'test', 'last_activity' => time()]);
        $token = Password::createToken($user);
        $payload = ['email' => $user->email, 'token' => $token, 'password' => 'Reset!Password123', 'password_confirmation' => 'Reset!Password123'];
        $this->postJson('/reset-password', $payload)->assertOk();
        $this->assertTrue(Hash::check('Reset!Password123', $user->fresh()->password));
        $this->assertDatabaseMissing('sessions', ['id' => 'old-device']);
        $this->postJson('/reset-password', $payload)->assertUnprocessable();
    }

    public function test_invalid_and_expired_reset_tokens_are_rejected(): void
    {
        $user = User::factory()->create();
        $payload = ['email' => $user->email, 'token' => 'invalid', 'password' => 'Reset!Password123', 'password_confirmation' => 'Reset!Password123'];
        $this->postJson('/reset-password', $payload)->assertUnprocessable();
        $payload['token'] = Password::createToken($user);
        DB::table('password_reset_tokens')->where('email', $user->email)->update(['created_at' => now()->subHours(2)]);
        $this->postJson('/reset-password', $payload)->assertUnprocessable();
    }

    public function test_csrf_is_enforced_for_browser_mutations(): void
    {
        // Laravel normally bypasses CSRF while testing; explicitly exercise the real middleware.
        $this->app['env'] = 'local';
        $this->postJson('/login', ['email' => 'test@example.test', 'password' => 'bad'])->assertStatus(419);
        $this->get('/sanctum/csrf-cookie')->assertNoContent()->assertCookie('XSRF-TOKEN');
    }

    public function test_cors_only_allows_the_configured_frontend(): void
    {
        $this->withHeaders(['Origin' => config('insight.frontend_url'), 'Access-Control-Request-Method' => 'POST', 'Access-Control-Request-Headers' => 'Content-Type,X-XSRF-TOKEN'])->options('/login')
            ->assertNoContent()->assertHeader('Access-Control-Allow-Origin', config('insight.frontend_url'))->assertHeader('Access-Control-Allow-Credentials', 'true');
        $this->withHeaders(['Origin' => 'https://untrusted.example', 'Access-Control-Request-Method' => 'POST'])->options('/login')->assertHeader('Access-Control-Allow-Origin',config('insight.frontend_url'));
    }
}
