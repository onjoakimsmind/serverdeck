<?php

namespace App\Services;

use App\Exceptions\DokployException;
use App\Models\Setting;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class DokployService
{
    public const DEFAULT_URL = 'http://127.0.0.1:3000';

    private const RESOURCES = [
        'application' => [
            'collection' => 'applications',
            'label' => 'Application',
            'id' => 'applicationId',
            'status' => 'applicationStatus',
            'actions' => [
                'start' => 'application.start',
                'stop' => 'application.stop',
                'redeploy' => 'application.redeploy',
                'delete' => 'application.delete',
            ],
        ],
        'compose' => [
            'collection' => 'compose',
            'label' => 'Compose',
            'id' => 'composeId',
            'status' => 'composeStatus',
            'actions' => [
                'start' => 'compose.start',
                'stop' => 'compose.stop',
                'redeploy' => 'compose.redeploy',
                'delete' => 'compose.delete',
            ],
        ],
        'postgres' => [
            'collection' => 'postgres',
            'label' => 'PostgreSQL',
            'id' => 'postgresId',
            'status' => 'applicationStatus',
            'actions' => [
                'start' => 'postgres.start',
                'stop' => 'postgres.stop',
                'remove' => 'postgres.remove',
            ],
        ],
        'redis' => [
            'collection' => 'redis',
            'label' => 'Redis',
            'id' => 'redisId',
            'status' => 'applicationStatus',
            'actions' => [
                'start' => 'redis.start',
                'stop' => 'redis.stop',
                'remove' => 'redis.remove',
            ],
        ],
        'mysql' => [
            'collection' => 'mysql',
            'label' => 'MySQL',
            'id' => 'mysqlId',
            'status' => 'applicationStatus',
            'actions' => [
                'start' => 'mysql.start',
                'stop' => 'mysql.stop',
                'remove' => 'mysql.remove',
            ],
        ],
        'mariadb' => [
            'collection' => 'mariadb',
            'label' => 'MariaDB',
            'id' => 'mariadbId',
            'status' => 'applicationStatus',
            'actions' => [
                'start' => 'mariadb.start',
                'stop' => 'mariadb.stop',
                'remove' => 'mariadb.remove',
            ],
        ],
        'mongo' => [
            'collection' => 'mongo',
            'label' => 'MongoDB',
            'id' => 'mongoId',
            'status' => 'applicationStatus',
            'actions' => [
                'start' => 'mongo.start',
                'stop' => 'mongo.stop',
                'remove' => 'mongo.remove',
            ],
        ],
        'libsql' => [
            'collection' => 'libsql',
            'label' => 'LibSQL',
            'id' => 'libsqlId',
            'status' => 'applicationStatus',
            'actions' => [
                'start' => 'libsql.start',
                'stop' => 'libsql.stop',
                'remove' => 'libsql.remove',
            ],
        ],
    ];

    public function configured(): bool
    {
        return filled($this->token());
    }

    public function baseUrl(): string
    {
        return rtrim(Setting::get('dokploy.url', self::DEFAULT_URL) ?: self::DEFAULT_URL, '/');
    }

    public function tokenHint(): ?string
    {
        $token = $this->token();

        if (! $token) {
            return null;
        }

        return strlen($token) > 8 ? '••••'.substr($token, -4) : '••••';
    }

    public function overview(): array
    {
        $base = [
            'configured' => $this->configured(),
            'available' => false,
            'url' => $this->baseUrl(),
            'error' => null,
            'projects' => [],
            'counts' => [
                'projects' => 0,
                'environments' => 0,
                'resources' => 0,
                'applications' => 0,
                'compose' => 0,
                'databases' => 0,
                'running' => 0,
                'attention' => 0,
            ],
            'generated_at' => now()->toIso8601String(),
        ];

        if (! $this->configured()) {
            return $base;
        }

        try {
            $projects = Cache::remember('dokploy.overview', 20, fn () => $this->fetchProjects());
        } catch (\Throwable $exception) {
            return [
                ...$base,
                'error' => $exception->getMessage(),
            ];
        }

        $resources = collect($projects)->flatMap(
            fn (array $project) => collect($project['environments'])->flatMap(fn (array $environment) => $environment['resources']),
        );

        return [
            ...$base,
            'available' => true,
            'projects' => $projects,
            'counts' => [
                'projects' => count($projects),
                'environments' => collect($projects)->sum(fn (array $project) => count($project['environments'])),
                'resources' => $resources->count(),
                'applications' => $resources->where('type', 'application')->count(),
                'compose' => $resources->where('type', 'compose')->count(),
                'databases' => $resources->filter(fn (array $resource) => ! in_array($resource['type'], ['application', 'compose'], true))->count(),
                'running' => $resources->filter(fn (array $resource) => $resource['running'])->count(),
                'attention' => $resources->filter(fn (array $resource) => $resource['attention'])->count(),
            ],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    public function testConnection(): int
    {
        return count($this->call('get', 'project.all'));
    }

    public function createProject(string $name, ?string $description): array
    {
        $data = $this->call('post', 'project.create', [
            'name' => $name,
            'description' => $description,
        ]);

        $this->forget();

        return $data;
    }

    public function updateProject(string $projectId, string $name, ?string $description): array
    {
        $data = $this->call('post', 'project.update', [
            'projectId' => $projectId,
            'name' => $name,
            'description' => $description,
        ]);

        $this->forget();

        return $data;
    }

    public function deleteProject(string $projectId): void
    {
        $this->call('post', 'project.remove', ['projectId' => $projectId]);
        $this->forget();
    }

    public function createEnvironment(string $projectId, string $name, ?string $description): array
    {
        $data = $this->call('post', 'environment.create', [
            'projectId' => $projectId,
            'name' => $name,
            'description' => $description ?? '',
        ]);

        $this->forget();

        return $data;
    }

    public function deleteEnvironment(string $environmentId): void
    {
        $this->call('post', 'environment.remove', ['environmentId' => $environmentId]);
        $this->forget();
    }

    public function createApplication(
        string $environmentId,
        string $name,
        ?string $description,
        string $dockerImage,
        bool $deploy,
    ): array {
        $created = $this->call('post', 'application.create', [
            'name' => $name,
            'appName' => Str::limit(Str::slug($name) ?: 'app', 50, ''),
            'description' => $description,
            'environmentId' => $environmentId,
            'sourceType' => 'docker',
        ]);

        $applicationId = $created['applicationId'] ?? null;

        if (! is_string($applicationId) || $applicationId === '') {
            throw new DokployException('Dokploy did not return the new application identifier.');
        }

        try {
            $this->call('post', 'application.saveDockerProvider', [
                'applicationId' => $applicationId,
                'dockerImage' => $dockerImage,
                'username' => null,
                'password' => null,
                'registryUrl' => null,
            ]);

            if ($deploy) {
                $this->call('post', 'application.deploy', [
                    'applicationId' => $applicationId,
                    'title' => 'Deployed from ServerDeck',
                    'description' => 'Created by a ServerDeck operator.',
                ]);
            }
        } catch (\Throwable $exception) {
            try {
                $this->call('post', 'application.delete', ['applicationId' => $applicationId]);
            } catch (\Throwable) {
                // The original failure is the useful one; leave cleanup best-effort.
            }

            $this->forget();

            throw $exception;
        }

        $this->forget();

        return [
            'applicationId' => $applicationId,
            'name' => $name,
            'appName' => $created['appName'] ?? null,
            'deployed' => $deploy,
        ];
    }

    public function resourceAction(string $type, string $action, string $resourceId, bool $deleteVolumes = false): void
    {
        $config = self::RESOURCES[$type] ?? null;

        if (! $config) {
            throw new DokployException('Unsupported Dokploy resource type.');
        }

        $endpoint = $config['actions'][$action] ?? null;

        if (! $endpoint) {
            throw new DokployException('Unsupported action for this Dokploy resource.');
        }

        $payload = [$config['id'] => $resourceId];

        if ($type === 'compose' && $action === 'delete') {
            $payload['deleteVolumes'] = $deleteVolumes;
        }

        $this->call('post', $endpoint, $payload);
        $this->forget();
    }

    public function resourceTypes(): array
    {
        return array_keys(self::RESOURCES);
    }

    public function forget(): void
    {
        Cache::forget('dokploy.overview');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchProjects(): array
    {
        $projects = $this->call('get', 'project.all');

        return collect($projects)
            ->filter(fn ($project) => is_array($project) && isset($project['projectId']))
            ->map(function (array $summary) {
                try {
                    $detail = $this->call('get', 'project.one', ['projectId' => $summary['projectId']]);
                } catch (\Throwable) {
                    $detail = $summary;
                }

                return $this->normalizeProject([...$summary, ...$detail]);
            })
            ->values()
            ->all();
    }

    private function normalizeProject(array $project): array
    {
        $environments = collect($project['environments'] ?? [])
            ->filter(fn ($environment) => is_array($environment))
            ->map(function (array $environment) {
                $resources = collect(self::RESOURCES)
                    ->flatMap(function (array $config) use ($environment) {
                        return collect($environment[$config['collection']] ?? [])
                            ->filter(fn ($resource) => is_array($resource) && ! empty($resource[$config['id']]))
                            ->map(function (array $resource) use ($config) {
                                $status = strtolower((string) ($resource[$config['status']] ?? 'unknown'));

                                return [
                                    'type' => $config['collection'] === 'applications' ? 'application' : $config['collection'],
                                    'type_label' => $config['label'],
                                    'id' => (string) $resource[$config['id']],
                                    'name' => (string) ($resource['name'] ?? $resource['appName'] ?? $resource[$config['id']]),
                                    'app_name' => (string) ($resource['appName'] ?? ''),
                                    'description' => (string) ($resource['description'] ?? ''),
                                    'status' => $status,
                                    'running' => in_array($status, ['done', 'running'], true),
                                    'attention' => in_array($status, ['error', 'failed'], true),
                                    'created_at' => $resource['createdAt'] ?? $resource['created_at'] ?? null,
                                ];
                            });
                    })
                    ->sortBy(fn (array $resource) => [$resource['type'], strtolower($resource['name'])])
                    ->values()
                    ->all();

                return [
                    'id' => (string) ($environment['environmentId'] ?? ''),
                    'name' => (string) ($environment['name'] ?? 'environment'),
                    'description' => (string) ($environment['description'] ?? ''),
                    'is_default' => (bool) ($environment['isDefault'] ?? false),
                    'created_at' => $environment['createdAt'] ?? $environment['created_at'] ?? null,
                    'resources' => $resources,
                ];
            })
            ->values()
            ->all();

        return [
            'id' => (string) ($project['projectId'] ?? ''),
            'name' => (string) ($project['name'] ?? 'project'),
            'description' => (string) ($project['description'] ?? ''),
            'created_at' => $project['createdAt'] ?? $project['created_at'] ?? null,
            'environments' => $environments,
        ];
    }

    private function token(): ?string
    {
        return Setting::get('dokploy.token');
    }

    private function client(): PendingRequest
    {
        $base = $this->baseUrl();
        $api = str_ends_with($base, '/api') ? $base : $base.'/api';

        return Http::baseUrl($api)
            ->withHeaders(['x-api-key' => (string) $this->token()])
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout(20);
    }

    private function call(string $method, string $path, array $payload = []): array
    {
        if (! $this->configured()) {
            throw new DokployException('Dokploy is not configured yet. Add an API token in Settings.');
        }

        $response = $method === 'get'
            ? $this->client()->get($path, $payload)
            : $this->client()->post($path, $payload);

        if ($response->failed()) {
            throw new DokployException($this->errorMessage($response));
        }

        $body = trim($response->body());

        if ($body === '') {
            return [];
        }

        $json = $response->json();

        return is_array($json) ? $json : ['value' => $json];
    }

    private function errorMessage(Response $response): string
    {
        $data = $response->json();
        $message = is_array($data) ? ($data['message'] ?? null) : null;

        if (! is_string($message) || $message === '') {
            $message = 'Dokploy rejected the request.';
        }

        $fieldErrors = is_array($data) ? ($data['data']['zodError']['fieldErrors'] ?? []) : [];

        if (is_array($fieldErrors) && $fieldErrors !== []) {
            $first = collect($fieldErrors)->flatten()->first();

            if (is_string($first) && $first !== '') {
                $message .= ' '.$first;
            }
        }

        return sprintf('%s (HTTP %d)', $message, $response->status());
    }
}
