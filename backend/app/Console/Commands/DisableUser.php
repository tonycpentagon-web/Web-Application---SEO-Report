<?php

namespace App\Console\Commands;

use App\Models\User;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DisableUser extends Command
{
    protected $signature = 'admin:disable-user';

    protected $description = 'Disable one account after verifying a different, successfully signed-in Super Admin; retain all projects.';

    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Run this command in an interactive terminal.');

            return self::FAILURE;
        }
        $targetEmail = mb_strtolower(trim((string) $this->ask('Email of the account to disable')));
        $replacementEmail = mb_strtolower(trim((string) $this->ask('Email of your verified replacement Super Admin')));
        $password = $this->secret('Replacement Super Admin password', false);
        $validator = validator(['target' => $targetEmail, 'replacement' => $replacementEmail], [
            'target' => 'required|email|max:254',
            'replacement' => 'required|email|max:254|different:target',
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }
        if (! $this->confirm('Disable this account and revoke its access? Its projects and records will be retained.', false)) {
            $this->info('Cancelled. No records changed.');

            return self::SUCCESS;
        }
        try {
            DB::transaction(function () use ($targetEmail, $replacementEmail, $password) {
                $users = User::whereIn('email', [$targetEmail, $replacementEmail])->orderBy('id')->lockForUpdate()->get()->keyBy('email');
                $replacement = $users->get($replacementEmail);
                $target = $users->get($targetEmail);
                if (! $replacement || ! $replacement->is_super_admin || ! $replacement->is_active || ! $replacement->last_login_at || ! is_string($password) || ! Hash::check($password, $replacement->password)) {
                    throw new DomainException('Replacement must be an active Super Admin with a successful login and matching password. Sign in with your new account first.');
                }
                if (! $target) {
                    throw new DomainException('The account to disable was not found. No records changed.');
                }
                $target->forceFill(['is_active' => false, 'remember_token' => Str::random(60)])->save();
                DB::table('sessions')->where('user_id', $target->id)->delete();
                DB::table('password_reset_tokens')->where('email', $target->email)->delete();
                DB::table('personal_access_tokens')->where('tokenable_type', $target->getMorphClass())->where('tokenable_id', $target->id)->delete();
                Log::info('Account disabled through console.', ['user_id' => $target->id, 'verified_by' => $replacement->id]);
            });
        } catch (DomainException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            unset($password);
        }
        $this->info('Account disabled and access revoked. All projects and other records were retained.');

        return self::SUCCESS;
    }
}
