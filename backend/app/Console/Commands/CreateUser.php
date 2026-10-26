<?php

namespace App\Console\Commands;

class CreateUser extends CreateAdmin
{
    protected $signature = 'app:create-user';

    protected $description = 'Securely create a regular team account with hidden password prompts.';

    protected function superAdmin(): bool
    {
        return false;
    }
}
