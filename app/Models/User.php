<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['username', 'name', 'email', 'password', 'role', 'title', 'last_seen_at', 'linux_uid', 'linux_groups', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'role' => UserRole::class,
            'last_seen_at' => 'datetime',
            'linux_groups' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function getInitialsAttribute(): string
    {
        return collect(explode(' ', $this->name))
            ->filter()
            ->map(fn (string $part) => mb_substr($part, 0, 1))
            ->take(2)
            ->implode('');
    }

    public function isAdmin(): bool
    {
        return $this->is_active && $this->role === UserRole::Admin;
    }

    public function canOperate(): bool
    {
        return $this->is_active && in_array($this->role, [UserRole::Admin, UserRole::Operator], true);
    }

    public function canUseTerminal(): bool
    {
        return $this->is_active && $this->role === UserRole::Admin;
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }
}
