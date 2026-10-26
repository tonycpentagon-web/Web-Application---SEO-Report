<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules\Password;

class CreateAdmin extends Command
{
    protected $signature = 'admin:create';

    protected $description = 'Securely create a Super Admin with interactive, hidden password prompts.';

    protected function superAdmin(): bool
    {
        return true;
    }

    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Run this command in an interactive terminal. Password arguments and environment credentials are not supported.');

            return self::FAILURE;
        }

        $name = trim((string) $this->ask('Name'));
        $email = mb_strtolower(trim((string) $this->ask('Email')));
        // Never fall back to visible password entry if this terminal cannot hide input.
        $password = $this->secret('Password (12+ characters, uppercase, lowercase, number and symbol)', false);
        $confirmation = $this->secret('Confirm password', false);

        $validator = validator([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $confirmation,
        ], [
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:254|unique:users,email',
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = new User;
        $user->forceFill([
            'name' => $name, 'email' => $email,
            'password' => Hash::make($password),
            'is_super_admin' => $this->superAdmin(),
            'role' => $this->superAdmin() ? 'super_admin' : 'seo_executive',
            'is_active' => true,
        ])->save();
        unset($password, $confirmation, $validator);

        Log::info('Account provisioned through console.', ['user_id' => $user->id, 'super_admin' => $user->is_super_admin]);
        $this->info($this->superAdmin() ? 'Super Admin created. Sign in through the login page to verify access.' : 'Account created. Sign in through the login page.');

        return self::SUCCESS;
    }
}
