<?php

namespace App\Services\Host;

use App\Enums\UserRole;
use App\Models\User;

class LinuxAccountProvisioner
{
    /**
     * Mirror a successfully authenticated Linux account into the panel.
     * Existing panel roles are preserved; new sudo-capable accounts become
     * administrators and everyone else starts read-only.
     */
    public function provision(array $account): User
    {
        $username = (string) $account['username'];
        $groups = array_map('strtolower', $account['groups'] ?? []);
        $isSudo = in_array('sudo', $groups, true) || in_array('wheel', $groups, true);

        $user = User::query()->where('username', $username)->first();

        if (! $user) {
            $user = new User([
                'username' => $username,
                'name' => $account['name'] ?? $username,
                'role' => $isSudo ? UserRole::Admin : UserRole::Viewer,
                'title' => $isSudo ? 'Host administrator' : 'Local Linux account',
                'is_active' => true,
            ]);
        }

        $user->forceFill([
            'name' => $user->name ?: ($account['name'] ?? $username),
            'linux_uid' => $account['uid'] ?? null,
            'linux_groups' => $groups,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        return $user;
    }
}
