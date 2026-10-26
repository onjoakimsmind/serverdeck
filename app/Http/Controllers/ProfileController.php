<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\AuditLogger;
use App\Services\DokployService;
use App\Services\Host\HostMetricsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function edit(Request $request, HostMetricsService $metrics, DokployService $dokploy): Response
    {
        $user = $request->user();

        return Inertia::render('Settings/Profile', [
            'user' => $user,
            'host' => $metrics->identity(),
            'integrations' => $user->isAdmin() ? [
                'dokploy' => [
                    'url' => Setting::get('dokploy.url', DokployService::DEFAULT_URL),
                    'configured' => $dokploy->configured(),
                    'token_hint' => $dokploy->tokenHint(),
                ],
            ] : null,
        ]);
    }

    public function update(Request $request, AuditLogger $audit): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'title' => ['nullable', 'string', 'max:100'],
        ]);

        $user->update($validated);

        $audit->log('profile.updated', "{$user->name} updated their profile", $user);

        return back()->with('success', 'Your profile was updated.');
    }

    public function updateDokploy(Request $request, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'dokploy_url' => ['nullable', 'string', 'max:255', 'url:http,https'],
            'dokploy_token' => ['nullable', 'string', 'max:500'],
            'clear_token' => ['boolean'],
        ]);

        $url = trim((string) ($validated['dokploy_url'] ?? ''));

        Setting::put('dokploy.url', $url !== '' ? rtrim($url, '/') : null);

        if ($request->boolean('clear_token')) {
            Setting::put('dokploy.token', null);
        } elseif (filled($validated['dokploy_token'] ?? null)) {
            Setting::put('dokploy.token', trim($validated['dokploy_token']), true);
        }

        Cache::forget('dokploy.overview');

        $audit->log('integration.dokploy.updated', "{$request->user()->name} updated the Dokploy integration", null, [
            'url' => $url ?: DokployService::DEFAULT_URL,
            'token_changed' => $request->boolean('clear_token') || filled($validated['dokploy_token'] ?? null),
            'token_cleared' => $request->boolean('clear_token'),
        ]);

        return back()->with('success', 'Dokploy integration settings were saved.');
    }
}
