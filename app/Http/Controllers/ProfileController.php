<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\AuditLogger;
use App\Services\DokployService;
use App\Services\Host\HostMetricsService;
use App\Services\HubReportingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function edit(Request $request, HostMetricsService $metrics, DokployService $dokploy, HubReportingService $hub): Response
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
                'hub' => [
                    'url' => Setting::get('hub.url'),
                    'configured' => $hub->configured(),
                    'token_hint' => $hub->tokenHint(),
                    'last_reported_at' => $hub->lastReportedAt(),
                    'last_status' => $hub->lastStatus(),
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

    public function updateHub(Request $request, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'hub_url' => ['nullable', 'string', 'max:255', 'url:http,https'],
            'hub_token' => ['nullable', 'string', 'max:500'],
            'clear_token' => ['boolean'],
        ]);

        $url = trim((string) ($validated['hub_url'] ?? ''));

        Setting::put('hub.url', $url !== '' ? rtrim($url, '/') : null);

        if ($request->boolean('clear_token')) {
            Setting::put('hub.token', null);
        } elseif (filled($validated['hub_token'] ?? null)) {
            Setting::put('hub.token', trim($validated['hub_token']), true);
        }

        $audit->log('integration.hub.updated', "{$request->user()->name} updated the Hub reporting integration", null, [
            'url' => $url ?: null,
            'token_changed' => $request->boolean('clear_token') || filled($validated['hub_token'] ?? null),
            'token_cleared' => $request->boolean('clear_token'),
        ]);

        return back()->with('success', 'Hub reporting settings were saved.');
    }

    public function reportHubNow(HubReportingService $hub, AuditLogger $audit): RedirectResponse
    {
        if (! $hub->configured()) {
            return back()->with('error', 'Configure a Hub URL and token before sending a report.');
        }

        $result = $hub->report();

        $audit->log('integration.hub.reported', 'A manual Hub status report was sent', null, $result);

        if ($result['sent']) {
            return back()->with('success', 'Status report sent to the Hub.');
        }

        return back()->with('error', "Hub report was not sent ({$result['reason']}).");
    }
}
