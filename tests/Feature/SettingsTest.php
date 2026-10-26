<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\DokployService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use Tests\Concerns\InteractsWithHost;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use InteractsWithHost, RefreshDatabase;

    public function test_administrators_see_the_dokploy_integration_state(): void
    {
        $this->fakeHostTelemetry();

        Setting::put('dokploy.url', 'http://dokploy.internal:3000');
        Setting::put('dokploy.token', 'super-secret-token', true);

        $this->mock(DokployService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('configured')->once()->andReturn(true);
            $mock->shouldReceive('tokenHint')->once()->andReturn('••••oken');
        });

        $this->actingAs(User::factory()->admin()->create())
            ->get('/settings')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/Profile')
                ->where('integrations.dokploy.url', 'http://dokploy.internal:3000')
                ->where('integrations.dokploy.configured', true)
                ->where('integrations.dokploy.token_hint', '••••oken')
                ->etc()
            );
    }

    public function test_viewers_do_not_receive_integration_secrets(): void
    {
        $this->fakeHostTelemetry();

        Setting::put('dokploy.token', 'super-secret-token', true);

        $this->mock(DokployService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('configured');
            $mock->shouldNotReceive('tokenHint');
        });

        $this->actingAs(User::factory()->viewer()->create())
            ->get('/settings')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/Profile')
                ->where('integrations', null)
                ->etc()
            );
    }

    public function test_administrators_can_store_the_dokploy_token_encrypted(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->put('/settings/dokploy', [
                'dokploy_url' => 'http://127.0.0.1:3000/',
                'dokploy_token' => 'plain-text-api-key',
                'clear_token' => false,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $setting = Setting::query()->where('key', 'dokploy.token')->firstOrFail();

        $this->assertTrue($setting->encrypted);
        $this->assertNotSame('plain-text-api-key', $setting->value);
        $this->assertSame('plain-text-api-key', Crypt::decryptString($setting->value));
        $this->assertSame('plain-text-api-key', Setting::get('dokploy.token'));
        $this->assertSame('http://127.0.0.1:3000', Setting::get('dokploy.url'));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'integration.dokploy.updated',
        ]);
    }

    public function test_an_empty_token_field_keeps_the_existing_token(): void
    {
        Setting::put('dokploy.token', 'existing-token', true);

        $this->actingAs(User::factory()->admin()->create())
            ->put('/settings/dokploy', [
                'dokploy_url' => 'http://127.0.0.1:3000',
                'dokploy_token' => '',
                'clear_token' => false,
            ])
            ->assertRedirect();

        $this->assertSame('existing-token', Setting::get('dokploy.token'));
    }

    public function test_administrators_can_clear_the_stored_token(): void
    {
        Setting::put('dokploy.token', 'existing-token', true);

        $this->actingAs(User::factory()->admin()->create())
            ->put('/settings/dokploy', [
                'dokploy_url' => 'http://127.0.0.1:3000',
                'clear_token' => true,
            ])
            ->assertRedirect();

        $this->assertDatabaseMissing('settings', ['key' => 'dokploy.token']);
        $this->assertNull(Setting::get('dokploy.token'));
    }

    public function test_invalid_integration_urls_are_rejected(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->put('/settings/dokploy', [
                'dokploy_url' => 'ftp://not-http',
                'clear_token' => false,
            ])
            ->assertSessionHasErrors('dokploy_url');

        $this->assertDatabaseMissing('settings', ['key' => 'dokploy.url']);
    }

    public function test_only_administrators_can_change_integration_settings(): void
    {
        $this->actingAs(User::factory()->operator()->create())
            ->put('/settings/dokploy', ['dokploy_url' => 'http://example.test'])
            ->assertForbidden();

        $this->actingAs(User::factory()->viewer()->create())
            ->put('/settings/dokploy', ['dokploy_url' => 'http://example.test'])
            ->assertForbidden();

        $this->assertDatabaseMissing('settings', ['key' => 'dokploy.url']);
    }

    public function test_users_can_update_their_own_profile(): void
    {
        $user = User::factory()->viewer()->create(['name' => 'Old Name']);

        $this->actingAs($user)
            ->put('/settings/profile', [
                'name' => 'New Name',
                'title' => 'Platform observer',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('New Name', $user->fresh()->name);
        $this->assertSame('Platform observer', $user->fresh()->title);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'profile.updated',
            'subject_id' => $user->id,
        ]);
    }
}
