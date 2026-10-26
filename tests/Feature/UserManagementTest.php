<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\InteractsWithHost;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use InteractsWithHost, RefreshDatabase;

    public function test_administrators_can_list_panel_accounts(): void
    {
        $this->fakeHostTelemetry();

        $admin = User::factory()->admin()->create(['name' => 'Admin One']);
        User::factory()->operator()->create(['name' => 'Operator One']);
        User::factory()->viewer()->create(['name' => 'Viewer One']);

        $this->actingAs($admin)
            ->get('/users')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Users/Index')
                ->has('users.data', 3)
                ->where('counts.all', 3)
                ->where('counts.admin', 1)
                ->where('counts.operator', 1)
                ->where('counts.viewer', 1)
                ->where('counts.active', 3)
                ->has('roles', 3)
                ->etc()
            );
    }

    public function test_administrators_can_change_roles_and_the_change_is_audited(): void
    {
        $admin = User::factory()->admin()->create();
        $operator = User::factory()->operator()->create(['name' => 'Sam Operator']);

        $this->actingAs($admin)
            ->put("/users/{$operator->id}", [
                'name' => 'Sam Operator',
                'title' => 'Workload operator',
                'role' => UserRole::Viewer->value,
                'is_active' => true,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(UserRole::Viewer, $operator->fresh()->role);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user.updated',
            'subject_id' => $operator->id,
        ]);
    }

    public function test_administrators_can_revoke_access_without_deleting_the_linux_account(): void
    {
        $admin = User::factory()->admin()->create();
        $viewer = User::factory()->viewer()->create(['name' => 'Val Viewer']);

        $this->actingAs($admin)
            ->put("/users/{$viewer->id}", [
                'name' => 'Val Viewer',
                'title' => 'Contractor',
                'role' => UserRole::Viewer->value,
                'is_active' => false,
            ])
            ->assertRedirect();

        $this->assertFalse($viewer->fresh()->is_active);
        $this->assertFalse($viewer->fresh()->canOperate());
        $this->assertDatabaseHas('users', ['id' => $viewer->id]);
    }

    public function test_administrators_cannot_change_their_own_role_or_disable_themselves(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->put("/users/{$admin->id}", [
                'name' => $admin->name,
                'title' => $admin->title,
                'role' => UserRole::Viewer->value,
                'is_active' => true,
            ])
            ->assertSessionHasErrors('role');

        $this->actingAs($admin)
            ->put("/users/{$admin->id}", [
                'name' => $admin->name,
                'title' => $admin->title,
                'role' => UserRole::Admin->value,
                'is_active' => false,
            ])
            ->assertSessionHasErrors('is_active');

        $this->assertSame(UserRole::Admin, $admin->fresh()->role);
        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_non_administrators_cannot_manage_accounts(): void
    {
        $this->fakeHostTelemetry();

        $operator = User::factory()->operator()->create();
        $viewer = User::factory()->viewer()->create();

        $this->actingAs($operator)->get('/users')->assertForbidden();
        $this->actingAs($viewer)->get('/users')->assertForbidden();

        $this->actingAs($operator)
            ->put("/users/{$viewer->id}", [
                'name' => 'Escalated',
                'role' => UserRole::Admin->value,
                'is_active' => true,
            ])
            ->assertForbidden();

        $this->assertSame(UserRole::Viewer, $viewer->fresh()->role);
    }
}
