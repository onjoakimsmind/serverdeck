<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use App\Services\DokployService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DokployController extends Controller
{
    public function index(DokployService $dokploy): Response
    {
        return Inertia::render('Dokploy/Index', [
            'dokploy' => $dokploy->overview(),
        ]);
    }

    public function storeProject(Request $request, DokployService $dokploy, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $data = $dokploy->createProject($validated['name'], $validated['description'] ?? null);
        } catch (\Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $projectId = $data['project']['projectId'] ?? null;

        $audit->log('dokploy.project.created', "{$request->user()->name} created Dokploy project {$validated['name']}", null, [
            'project_id' => $projectId,
            'name' => $validated['name'],
        ]);

        return back()->with('success', "Dokploy project {$validated['name']} was created.");
    }

    public function updateProject(Request $request, string $project, DokployService $dokploy, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $dokploy->updateProject($project, $validated['name'], $validated['description'] ?? null);
        } catch (\Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $audit->log('dokploy.project.updated', "{$request->user()->name} updated Dokploy project {$validated['name']}", null, [
            'project_id' => $project,
            'name' => $validated['name'],
        ]);

        return back()->with('success', "Dokploy project {$validated['name']} was updated.");
    }

    public function destroyProject(Request $request, string $project, DokployService $dokploy, AuditLogger $audit): RedirectResponse
    {
        try {
            $dokploy->deleteProject($project);
        } catch (\Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $audit->log('dokploy.project.deleted', "{$request->user()->name} deleted Dokploy project {$project}", null, [
            'project_id' => $project,
        ]);

        return back()->with('success', 'The Dokploy project was deleted.');
    }

    public function storeEnvironment(Request $request, DokployService $dokploy, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'project_id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $data = $dokploy->createEnvironment($validated['project_id'], $validated['name'], $validated['description'] ?? null);
        } catch (\Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $audit->log('dokploy.environment.created', "{$request->user()->name} created Dokploy environment {$validated['name']}", null, [
            'project_id' => $validated['project_id'],
            'environment_id' => $data['environmentId'] ?? null,
            'name' => $validated['name'],
        ]);

        return back()->with('success', "Dokploy environment {$validated['name']} was created.");
    }

    public function destroyEnvironment(Request $request, string $environment, DokployService $dokploy, AuditLogger $audit): RedirectResponse
    {
        try {
            $dokploy->deleteEnvironment($environment);
        } catch (\Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $audit->log('dokploy.environment.deleted', "{$request->user()->name} deleted Dokploy environment {$environment}", null, [
            'environment_id' => $environment,
        ]);

        return back()->with('success', 'The Dokploy environment was deleted.');
    }

    public function storeApplication(Request $request, DokployService $dokploy, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'environment_id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:500'],
            'docker_image' => ['required', 'string', 'max:300', 'regex:/^[A-Za-z0-9][A-Za-z0-9._\/:@-]*$/'],
            'deploy' => ['required', 'boolean'],
        ]);

        try {
            $data = $dokploy->createApplication(
                $validated['environment_id'],
                $validated['name'],
                $validated['description'] ?? null,
                $validated['docker_image'],
                $validated['deploy'],
            );
        } catch (\Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $audit->log('dokploy.application.created', "{$request->user()->name} created Dokploy application {$validated['name']}", null, [
            'environment_id' => $validated['environment_id'],
            'application_id' => $data['applicationId'],
            'docker_image' => $validated['docker_image'],
            'deployed' => $validated['deploy'],
        ]);

        return back()->with(
            'success',
            $validated['deploy']
                ? "Dokploy application {$validated['name']} was created and deployment was queued."
                : "Dokploy application {$validated['name']} was created.",
        );
    }

    public function resourceAction(
        Request $request,
        DokployService $dokploy,
        AuditLogger $audit,
        string $type,
        string $resource,
    ): RedirectResponse {
        abort_unless(in_array($type, $dokploy->resourceTypes(), true), 404);

        $validated = $request->validate([
            'action' => ['required', Rule::in(['start', 'stop', 'redeploy', 'delete', 'remove'])],
            'delete_volumes' => ['sometimes', 'boolean'],
        ]);

        try {
            $dokploy->resourceAction($type, $validated['action'], $resource, (bool) ($validated['delete_volumes'] ?? false));
        } catch (\Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $audit->log("dokploy.{$type}.{$validated['action']}", "{$request->user()->name} ran Dokploy {$validated['action']} on {$type} {$resource}", null, [
            'type' => $type,
            'resource_id' => $resource,
            'action' => $validated['action'],
        ]);

        return back()->with('success', "Dokploy {$validated['action']} completed for {$resource}.");
    }
}
