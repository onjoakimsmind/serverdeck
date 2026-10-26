<?php

namespace Tests\Feature;

use App\Exceptions\DokployException;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\DokployService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Concerns\InteractsWithHost;
use Tests\TestCase;

class DokployTest extends TestCase
{
    use InteractsWithHost, RefreshDatabase;

    public function test_operators_can_create_a_project(): void
    {
        $this->mock(DokployService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('createProject')
                ->once()
                ->with('storefront', 'Customer storefront')
                ->andReturn([
                    'project' => ['projectId' => 'project-1'],
                    'environment' => ['environmentId' => 'environment-1'],
                ]);
        });

        $this->actingAs(User::factory()->operator()->create())
            ->post('/dokploy/projects', [
                'name' => 'storefront',
                'description' => 'Customer storefront',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'dokploy.project.created',
            'subject_id' => null,
        ]);
    }

    public function test_operators_can_update_a_project(): void
    {
        $this->mock(DokployService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('updateProject')
                ->once()
                ->with('project-1', 'renamed', 'New description')
                ->andReturn([]);
        });

        $this->actingAs(User::factory()->operator()->create())
            ->put('/dokploy/projects/project-1', [
                'name' => 'renamed',
                'description' => 'New description',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('audit_logs', ['action' => 'dokploy.project.updated']);
    }

    public function test_operators_can_delete_a_project(): void
    {
        $this->mock(DokployService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('deleteProject')->once()->with('project-1');
        });

        $this->actingAs(User::factory()->operator()->create())
            ->delete('/dokploy/projects/project-1')
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'dokploy.project.deleted',
        ]);

        $this->assertStringContainsString(
            'deleted Dokploy project project-1',
            AuditLog::query()->where('action', 'dokploy.project.deleted')->latest('id')->value('description'),
        );
    }

    public function test_operators_can_create_and_delete_environments(): void
    {
        $this->mock(DokployService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('createEnvironment')
                ->once()
                ->with('project-1', 'staging', 'Pre-production')
                ->andReturn(['environmentId' => 'environment-2']);

            $mock->shouldReceive('deleteEnvironment')
                ->once()
                ->with('environment-2');
        });

        $operator = User::factory()->operator()->create();

        $this->actingAs($operator)
            ->post('/dokploy/environments', [
                'project_id' => 'project-1',
                'name' => 'staging',
                'description' => 'Pre-production',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('audit_logs', ['action' => 'dokploy.environment.created']);

        $this->actingAs($operator)
            ->delete('/dokploy/environments/environment-2')
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('audit_logs', ['action' => 'dokploy.environment.deleted']);
    }

    public function test_operators_can_create_a_docker_image_application(): void
    {
        $this->mock(DokployService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('createApplication')
                ->once()
                ->with('environment-1', 'api', 'Backend API', 'ghcr.io/example/api:1.2.3', true)
                ->andReturn([
                    'applicationId' => 'application-1',
                    'name' => 'api',
                    'appName' => 'api',
                    'deployed' => true,
                ]);
        });

        $this->actingAs(User::factory()->operator()->create())
            ->post('/dokploy/applications', [
                'environment_id' => 'environment-1',
                'name' => 'api',
                'description' => 'Backend API',
                'docker_image' => 'ghcr.io/example/api:1.2.3',
                'deploy' => true,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('audit_logs', ['action' => 'dokploy.application.created']);
    }

    public function test_application_creation_rejects_invalid_docker_image_references(): void
    {
        $this->mock(DokployService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('createApplication');
        });

        $this->actingAs(User::factory()->operator()->create())
            ->post('/dokploy/applications', [
                'environment_id' => 'environment-1',
                'name' => 'api',
                'docker_image' => 'not a valid image',
                'deploy' => false,
            ])
            ->assertSessionHasErrors('docker_image');
    }

    public function test_resource_lifecycle_actions_are_forwarded_and_audited(): void
    {
        $this->mock(DokployService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('resourceTypes')->andReturn([
                'application', 'compose', 'postgres', 'redis', 'mysql', 'mariadb', 'mongo', 'libsql',
            ]);
            $mock->shouldReceive('resourceAction')
                ->once()
                ->with('application', 'redeploy', 'application-1', false);
        });

        $this->actingAs(User::factory()->operator()->create())
            ->post('/dokploy/resources/application/application-1/action', [
                'action' => 'redeploy',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'dokploy.application.redeploy',
        ]);

        $this->assertStringContainsString(
            'ran Dokploy redeploy on application application-1',
            AuditLog::query()->where('action', 'dokploy.application.redeploy')->latest('id')->value('description'),
        );
    }

    public function test_unknown_resource_types_are_rejected(): void
    {
        $this->mock(DokployService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('resourceTypes')->andReturn(['application']);
            $mock->shouldNotReceive('resourceAction');
        });

        $this->actingAs(User::factory()->operator()->create())
            ->post('/dokploy/resources/secrets/application-1/action', [
                'action' => 'delete',
            ])
            ->assertNotFound();
    }

    public function test_dokploy_failures_are_reported_without_an_audit_entry(): void
    {
        $this->mock(DokployService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('createProject')
                ->once()
                ->andThrow(new DokployException('Dokploy rejected the request. (HTTP 401)'));
        });

        $this->actingAs(User::factory()->operator()->create())
            ->post('/dokploy/projects', ['name' => 'storefront'])
            ->assertRedirect()
            ->assertSessionHas('error', 'Dokploy rejected the request. (HTTP 401)');

        $this->assertDatabaseMissing('audit_logs', ['action' => 'dokploy.project.created']);
    }

    public function test_viewers_cannot_mutate_dokploy_resources(): void
    {
        $viewer = User::factory()->viewer()->create();

        $this->actingAs($viewer)
            ->post('/dokploy/projects', ['name' => 'forbidden'])
            ->assertForbidden();

        $this->actingAs($viewer)
            ->put('/dokploy/projects/project-1', ['name' => 'forbidden'])
            ->assertForbidden();

        $this->actingAs($viewer)
            ->delete('/dokploy/projects/project-1')
            ->assertForbidden();

        $this->actingAs($viewer)
            ->post('/dokploy/environments', [
                'project_id' => 'project-1',
                'name' => 'forbidden',
            ])
            ->assertForbidden();

        $this->actingAs($viewer)
            ->post('/dokploy/applications', [
                'environment_id' => 'environment-1',
                'name' => 'forbidden',
                'docker_image' => 'nginx:alpine',
                'deploy' => false,
            ])
            ->assertForbidden();

        $this->actingAs($viewer)
            ->post('/dokploy/resources/application/application-1/action', [
                'action' => 'delete',
            ])
            ->assertForbidden();
    }
}
