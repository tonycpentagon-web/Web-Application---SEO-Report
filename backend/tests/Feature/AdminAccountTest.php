<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AdminAccountTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Chosen!Password123';

    private const PASSWORD_PROMPT = 'Password (12+ characters, uppercase, lowercase, number and symbol)';

    private const DISABLE_CONFIRMATION = 'Disable this account and revoke its access? Its projects and records will be retained.';

    private function provision(string $email, string $password = self::PASSWORD, ?string $confirmation = null, string $command = 'admin:create')
    {
        return $this->artisan($command)
            ->expectsQuestion('Name', 'Account Owner')
            ->expectsQuestion('Email', $email)
            ->expectsQuestion(self::PASSWORD_PROMPT, $password)
            ->expectsQuestion('Confirm password', $confirmation ?? $password)
            ->doesntExpectOutputToContain($password);
    }

    private function disable(User $target, User $replacement, string $password = self::PASSWORD, bool $confirm = true)
    {
        return $this->artisan('admin:disable-user')
            ->expectsQuestion('Email of the account to disable', $target->email)
            ->expectsQuestion('Email of your verified replacement Super Admin', $replacement->email)
            ->expectsQuestion('Replacement Super Admin password', $password)
            ->expectsConfirmation(self::DISABLE_CONFIRMATION, $confirm ? 'yes' : 'no')
            ->doesntExpectOutputToContain($password);
    }

    public function test_super_admin_is_created_with_chosen_details_hashed_password_and_no_plaintext_log(): void
    {
        Log::spy();
        $this->provision('OWNER@example.test')->assertSuccessful();
        $user = User::where('email', 'owner@example.test')->firstOrFail();
        $this->assertTrue($user->is_super_admin);
        $this->assertTrue($user->is_active);
        $this->assertNull($user->last_login_at);
        $this->assertNotSame(self::PASSWORD, $user->getRawOriginal('password'));
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
        Log::shouldHaveReceived('info')->once()->with('Account provisioned through console.', ['user_id' => $user->id, 'super_admin' => true]);
    }

    public function test_duplicate_email_never_overwrites_existing_user_or_projects(): void
    {
        $existing = User::factory()->create(['email' => 'owner@example.test']);
        $project = Project::factory()->create(['user_id' => $existing->id]);
        $hash = $existing->password;
        $this->provision('owner@example.test')->assertFailed();
        $this->assertDatabaseCount('users', 1);
        $this->assertSame($hash, $existing->fresh()->password);
        $this->assertFalse($existing->fresh()->is_super_admin);
        $this->assertDatabaseHas('projects', ['id' => $project->id, 'user_id' => $existing->id]);
    }

    public function test_creation_rejects_weak_password_and_confirmation_mismatch(): void
    {
        $this->provision('weak@example.test', 'short')->assertFailed();
        $this->provision('mismatch@example.test', self::PASSWORD, 'Different!Password123')->assertFailed();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_regular_account_command_does_not_grant_super_admin(): void
    {
        $this->provision('team@example.test', command: 'app:create-user')->assertSuccessful();
        $this->assertFalse(User::firstOrFail()->is_super_admin);
    }

    public function test_noninteractive_provisioning_is_rejected(): void
    {
        $this->artisan('admin:create', ['--no-interaction' => true])->assertFailed();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_seeding_never_creates_or_changes_accounts_even_with_legacy_environment_values(): void
    {
        $existing = User::factory()->create();
        $original = $existing->fresh()->getAttributes();
        $this->stubLegacySeedVariables(function () {
            $this->seed();
        });
        $this->assertDatabaseCount('users', 1);
        $this->assertSame($original, $existing->fresh()->getAttributes());
    }

    private function stubLegacySeedVariables(callable $callback): void
    {
        $keys = ['SEED_ADMIN_EMAIL' => 'unused@example.test', 'SEED_ADMIN_PASSWORD' => self::PASSWORD];
        $previous = [];
        foreach ($keys as $key => $value) {
            $previous[$key] = getenv($key);
            putenv($key.'='.$value);
        }
        try {
            $callback();
        } finally {
            foreach ($previous as $key => $value) {
                $value === false ? putenv($key) : putenv($key.'='.$value);
            }
        }
    }

    public function test_first_successful_super_admin_login_records_verification_without_exposing_hash(): void
    {
        $this->provision('owner@example.test')->assertSuccessful();
        $this->postJson('/login', ['email' => 'OWNER@example.test', 'password' => self::PASSWORD])
            ->assertOk()->assertJsonPath('user.is_super_admin', true)->assertJsonMissingPath('user.password');
        $this->assertNotNull(User::firstOrFail()->last_login_at);
        $this->getJson('/api/user')->assertOk();
        $this->postJson('/logout')->assertOk();
    }

    public function test_disable_requires_replacement_with_successful_login_and_matching_password(): void
    {
        $target = User::factory()->create();
        $replacement = User::factory()->create(['is_super_admin' => true, 'password' => self::PASSWORD]);
        $this->disable($target, $replacement)->assertFailed();
        $this->assertTrue($target->fresh()->is_active);
        $replacement->forceFill(['last_login_at' => now()])->save();
        $this->disable($target, $replacement, 'Incorrect!Password123')->assertFailed();
        $this->assertTrue($target->fresh()->is_active);
        $replacement->forceFill(['is_super_admin' => false])->save();
        $this->disable($target, $replacement)->assertFailed();
        $this->assertTrue($target->fresh()->is_active);
    }

    public function test_disable_retains_projects_and_unrelated_records_and_revokes_only_target_access(): void
    {
        $target = User::factory()->create();
        $replacement = User::factory()->create(['is_super_admin' => true, 'last_login_at' => now(), 'password' => self::PASSWORD]);
        $project = Project::factory()->create(['user_id' => $target->id]);
        $otherProject = Project::factory()->create(['user_id' => $replacement->id]);
        foreach ([$target, $replacement] as $user) {
            DB::table('sessions')->insert(['id' => 'session-'.$user->id, 'user_id' => $user->id, 'payload' => 'test', 'last_activity' => time()]);
            Password::createToken($user);
        }
        $targetHash = $target->password;
        $this->disable($target, $replacement)->assertSuccessful();
        $this->assertFalse($target->fresh()->is_active);
        $this->assertSame($targetHash, $target->fresh()->password);
        $this->assertTrue($replacement->fresh()->is_active);
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseHas('projects', ['id' => $project->id, 'user_id' => $target->id]);
        $this->assertDatabaseHas('projects', ['id' => $otherProject->id, 'user_id' => $replacement->id]);
        $this->assertDatabaseMissing('sessions', ['user_id' => $target->id]);
        $this->assertDatabaseHas('sessions', ['user_id' => $replacement->id]);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $target->email]);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $replacement->email]);
    }

    public function test_cancelling_disable_preserves_access(): void
    {
        $target = User::factory()->create();
        $replacement = User::factory()->create(['is_super_admin' => true, 'last_login_at' => now(), 'password' => self::PASSWORD]);
        $this->disable($target, $replacement, confirm: false)->assertSuccessful();
        $this->assertTrue($target->fresh()->is_active);
    }

    public function test_self_disable_is_rejected_without_mutation(): void
    {
        $user = User::factory()->create(['is_super_admin' => true, 'last_login_at' => now(), 'password' => self::PASSWORD]);
        $this->artisan('admin:disable-user')
            ->expectsQuestion('Email of the account to disable', $user->email)
            ->expectsQuestion('Email of your verified replacement Super Admin', $user->email)
            ->expectsQuestion('Replacement Super Admin password', self::PASSWORD)
            ->assertFailed();
        $this->assertTrue($user->fresh()->is_active);
    }

    public function test_disabled_accounts_cannot_login_receive_reset_links_or_access_protected_routes(): void
    {
        Notification::fake();
        $user = User::factory()->create(['is_active' => false, 'password' => self::PASSWORD]);
        $this->postJson('/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertUnprocessable();
        $this->postJson('/forgot-password', ['email' => $user->email])->assertOk();
        Notification::assertNothingSent();
        $token = Password::createToken($user);
        $this->postJson('/reset-password', ['email' => $user->email, 'token' => $token, 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])->assertUnprocessable();
        $this->actingAs($user)->getJson('/api/user')->assertUnauthorized();
        $this->actingAs($user)->getJson('/api/projects')->assertUnauthorized();
        $this->actingAs($user)->putJson('/password', [])->assertUnauthorized();
    }

    public function test_super_admin_has_explicit_system_wide_project_access(): void
    {
        $foreign = Project::factory()->create();
        $admin = User::factory()->create(['is_super_admin' => true]);
        $this->actingAs($admin)->getJson('/api/projects/'.$foreign->id)->assertOk();
    }
}
