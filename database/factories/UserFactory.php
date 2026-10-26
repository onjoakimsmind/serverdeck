<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    public function definition(): array
    {
        $username = fake()->unique()->userName();

        return [
            'username' => $username,
            'name' => fake()->name(),
            'email' => null,
            'email_verified_at' => now(),
            'password' => null,
            'role' => UserRole::Viewer,
            'title' => 'Local Linux account',
            'is_active' => true,
            'linux_uid' => fake()->numberBetween(1000, 65000),
            'linux_groups' => ['users'],
            'remember_token' => Str::random(10),
        ];
    }

    public function admin(): static
    {
        return $this->state(fn () => ['role' => UserRole::Admin, 'linux_groups' => ['sudo']]);
    }

    public function operator(): static
    {
        return $this->state(fn () => ['role' => UserRole::Operator]);
    }

    public function viewer(): static
    {
        return $this->state(fn () => ['role' => UserRole::Viewer]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function unverified(): static
    {
        return $this->state(fn () => ['email_verified_at' => null]);
    }
}
