<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\Host\PamAuthenticator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use Tests\Concerns\InteractsWithHost;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use InteractsWithHost, RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_screen(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/incus')->assertRedirect('/login');
        $this->get('/dokploy')->assertRedirect('/login');
        $this->get('/settings')->assertRedirect('/login');
        $this->get('/terminal')->assertRedirect('/login');
    }

    public function test_the_login_screen_is_rendered_with_real_host_identity(): void
    {
        $this->fakeMetrics();

        $this->get('/login')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/Login')
                ->where('host.hostname', 'pve')
                ->where('host.os', 'Ubuntu 26.04.1 LTS')
                ->etc()
            );
    }

    public function test_pam_authentication_provisions_sudo_accounts_as_administrators(): void
    {
        $this->mock(PamAuthenticator::class, function (MockInterface $mock): void {
            $mock->shouldReceive('authenticate')
                ->once()
                ->with('alice', 'correct-horse')
                ->andReturn([
                    'username' => 'alice',
                    'name' => 'Alice Admin',
                    'uid' => 1001,
                    'groups' => ['alice', 'sudo'],
                ]);
        });

        $this->post('/login', [
            'username' => 'alice',
            'password' => 'correct-horse',
        ])->assertRedirect('/');

        $user = User::query()->where('username', 'alice')->firstOrFail();

        $this->assertSame(UserRole::Admin, $user->role);
        $this->assertSame(1001, $user->linux_uid);
        $this->assertSame(['alice', 'sudo'], $user->linux_groups);
        $this->assertTrue($user->is_active);
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'auth.login',
            'user_id' => $user->id,
        ]);
    }

    public function test_pam_authentication_provisions_regular_accounts_as_viewers(): void
    {
        $this->mock(PamAuthenticator::class, function (MockInterface $mock): void {
            $mock->shouldReceive('authenticate')
                ->once()
                ->with('bob', 'hunter2')
                ->andReturn([
                    'username' => 'bob',
                    'name' => 'Bob Viewer',
                    'uid' => 1002,
                    'groups' => ['bob', 'users'],
                ]);
        });

        $this->post('/login', [
            'username' => 'bob',
            'password' => 'hunter2',
        ])->assertRedirect('/');

        $this->assertSame(UserRole::Viewer, User::query()->where('username', 'bob')->value('role'));
    }

    public function test_existing_panel_roles_are_preserved_on_later_logins(): void
    {
        $user = User::factory()->admin()->create(['username' => 'carol']);

        $this->mock(PamAuthenticator::class, function (MockInterface $mock): void {
            $mock->shouldReceive('authenticate')
                ->once()
                ->andReturn([
                    'username' => 'carol',
                    'name' => 'Carol Admin',
                    'uid' => 1003,
                    'groups' => ['carol', 'users'],
                ]);
        });

        $this->post('/login', [
            'username' => 'carol',
            'password' => 'secret',
        ])->assertRedirect('/');

        $this->assertSame(UserRole::Admin, $user->fresh()->role);
    }

    public function test_failed_pam_authentication_is_rejected_without_creating_an_account(): void
    {
        $this->mock(PamAuthenticator::class, function (MockInterface $mock): void {
            $mock->shouldReceive('authenticate')->once()->andReturnNull();
        });

        $this->post('/login', [
            'username' => 'mallory',
            'password' => 'wrong',
        ])->assertSessionHasErrors('username');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['username' => 'mallory']);
    }

    public function test_disabled_accounts_cannot_sign_in(): void
    {
        $user = User::factory()->inactive()->create(['username' => 'dana']);

        $this->mock(PamAuthenticator::class, function (MockInterface $mock): void {
            $mock->shouldReceive('authenticate')->once()->andReturn([
                'username' => 'dana',
                'name' => 'Dana Disabled',
                'uid' => 1004,
                'groups' => ['dana'],
            ]);
        });

        $this->post('/login', [
            'username' => 'dana',
            'password' => 'secret',
        ])->assertSessionHasErrors('username');

        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'auth.blocked',
            'user_id' => $user->id,
        ]);
    }

    public function test_users_can_sign_out_and_the_action_is_audited(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/logout')
            ->assertRedirect('/login');

        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'auth.logout',
            'user_id' => $user->id,
        ]);
    }
}
