<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\DokployService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
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

    public function test_administrators_see_the_hub_integration_state(): void
    {
        $this->fakeHostTelemetry();

        Setting::put('hub.url', 'https://hub.example.com');
        Setting::put('hub.token', 'enrollment-token', true);
        Setting::put('hub.last_reported_at', '2026-10-26T13:00:00+00:00');
        Setting::put('hub.last_status', 'ok');

        $this->actingAs(User::factory()->admin()->create())
            ->get('/settings')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/Profile')
                ->where('integrations.hub.url', 'https://hub.example.com')
                ->where('integrations.hub.configured', true)
                ->where('integrations.hub.last_status', 'ok')
                ->etc()
            );
    }

    public function test_administrators_can_store_the_hub_token_encrypted(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->put('/settings/hub', [
                'hub_url' => 'https://hub.example.com/',
                'hub_token' => 'plain-text-enrollment-token',
                'clear_token' => false,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $setting = Setting::query()->where('key', 'hub.token')->firstOrFail();

        $this->assertTrue($setting->encrypted);
        $this->assertNotSame('plain-text-enrollment-token', $setting->value);
        $this->assertSame('plain-text-enrollment-token', Crypt::decryptString($setting->value));
        $this->assertSame('https://hub.example.com', Setting::get('hub.url'));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'integration.hub.updated',
        ]);
    }

    public function test_an_empty_hub_token_field_keeps_the_existing_token(): void
    {
        Setting::put('hub.token', 'existing-token', true);

        $this->actingAs(User::factory()->admin()->create())
            ->put('/settings/hub', [
                'hub_url' => 'https://hub.example.com',
                'hub_token' => '',
                'clear_token' => false,
            ])
            ->assertRedirect();

        $this->assertSame('existing-token', Setting::get('hub.token'));
    }

    public function test_administrators_can_clear_the_stored_hub_token(): void
    {
        Setting::put('hub.url', 'https://hub.example.com');
        Setting::put('hub.token', 'existing-token', true);

        $this->actingAs(User::factory()->admin()->create())
            ->put('/settings/hub', [
                'hub_url' => 'https://hub.example.com',
                'clear_token' => true,
            ])
            ->assertRedirect();

        $this->assertDatabaseMissing('settings', ['key' => 'hub.token']);
        $this->assertNull(Setting::get('hub.token'));
    }

    public function test_invalid_hub_urls_are_rejected(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->put('/settings/hub', [
                'hub_url' => 'ftp://not-http',
                'clear_token' => false,
            ])
            ->assertSessionHasErrors('hub_url');

        $this->assertDatabaseMissing('settings', ['key' => 'hub.url']);
    }

    public function test_only_administrators_can_change_hub_settings(): void
    {
        $this->actingAs(User::factory()->operator()->create())
            ->put('/settings/hub', ['hub_url' => 'https://hub.example.com'])
            ->assertForbidden();

        $this->actingAs(User::factory()->viewer()->create())
            ->put('/settings/hub', ['hub_url' => 'https://hub.example.com'])
            ->assertForbidden();

        $this->assertDatabaseMissing('settings', ['key' => 'hub.url']);
    }

    public function test_sending_a_hub_report_now_requires_configuration(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post('/settings/hub/report')
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_sending_a_hub_report_now_posts_the_payload(): void
    {
        $this->fakeHostTelemetry();

        Setting::put('hub.url', 'https://hub.example.com');
        Setting::put('hub.token', 'enrollment-token', true);

        Http::fake([
            'https://hub.example.com/api/ingest' => Http::response(['ok' => true], 200),
        ]);

        $this->actingAs(User::factory()->admin()->create())
            ->post('/settings/hub/report')
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'integration.hub.reported',
        ]);
    }
}
