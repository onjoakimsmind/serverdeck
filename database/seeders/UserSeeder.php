<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $account = function_exists('posix_getpwnam') ? posix_getpwnam('joakim') : false;
        $name = is_array($account) && ! empty($account['gecos']) ? $account['gecos'] : 'Joakim';

        User::query()->updateOrCreate(
            ['username' => 'joakim'],
            [
                'name' => $name,
                'email' => null,
                'email_verified_at' => now(),
                'password' => null,
                'role' => UserRole::Admin,
                'title' => 'Host administrator',
                'is_active' => true,
                'linux_uid' => is_array($account) ? $account['uid'] : null,
                'linux_groups' => ['sudo'],
            ],
        );
    }
}
