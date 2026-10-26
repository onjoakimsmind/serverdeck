<?php

use App\Http\Controllers\AlertController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DokployController;
use App\Http\Controllers\Host\BackupController;
use App\Http\Controllers\Host\ConsoleController;
use App\Http\Controllers\Host\DeviceController;
use App\Http\Controllers\Host\DiskController;
use App\Http\Controllers\Host\DockerController;
use App\Http\Controllers\Host\HealthController;
use App\Http\Controllers\Host\IncusController;
use App\Http\Controllers\Host\LogsController;
use App\Http\Controllers\Host\MetricsController;
use App\Http\Controllers\Host\NetworkController;
use App\Http\Controllers\Host\OverviewController;
use App\Http\Controllers\Host\ServicesController;
use App\Http\Controllers\Host\StorageController;
use App\Http\Controllers\Host\SystemController;
use App\Http\Controllers\Host\TerminalController;
use App\Http\Controllers\Host\UpdateController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');
});

Route::post('/logout', [LoginController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

Route::middleware('auth')->group(function () {
    Route::get('/', OverviewController::class)->name('dashboard');
    Route::get('/api/metrics', MetricsController::class)->name('metrics.live');
    Route::get('/api/metrics/history', [MetricsController::class, 'history'])->name('metrics.history');
    Route::get('/api/devices', [DeviceController::class, 'index'])->name('devices.index');

    Route::get('/system', [SystemController::class, 'index'])->name('system.index');
    Route::middleware('can:operate')->group(function () {
        Route::post('/system/processes/{pid}/signal', [SystemController::class, 'signal'])
            ->whereNumber('pid')
            ->name('system.processes.signal');
    });

    Route::get('/storage', [StorageController::class, 'index'])->name('storage.index');
    Route::get('/disks', [DiskController::class, 'index'])->name('disks.index');
    Route::post('/disks/scan', [DiskController::class, 'scan'])->name('disks.scan');
    Route::get('/updates', [UpdateController::class, 'index'])->name('updates.index');
    Route::get('/backups', [BackupController::class, 'index'])->name('backups.index');

    Route::get('/network', [NetworkController::class, 'index'])->name('network.index');
    Route::middleware('can:operate')->group(function () {
        Route::post('/network/tools/ping', [NetworkController::class, 'ping'])->name('network.tools.ping');
        Route::post('/network/tools/dig', [NetworkController::class, 'dig'])->name('network.tools.dig');
        Route::post('/network/tools/portcheck', [NetworkController::class, 'portCheck'])->name('network.tools.portcheck');
        Route::post('/network/firewall/rules', [NetworkController::class, 'firewallRuleStore'])->name('network.firewall.rules.store');
        Route::delete('/network/firewall/rules/{family}/{line}', [NetworkController::class, 'firewallRuleDestroy'])
            ->where('family', 'ipv4|ipv6')
            ->where('line', '[0-9]{1,3}')
            ->name('network.firewall.rules.destroy');
    });

    Route::get('/docker', [DockerController::class, 'index'])->name('docker.index');
    Route::get('/docker/logs', [DockerController::class, 'logs'])->name('docker.logs');
    Route::get('/incus', [IncusController::class, 'index'])->name('incus.index');
    Route::get('/incus/images', [IncusController::class, 'images'])->name('incus.images');
    Route::get('/incus/remote-images', [IncusController::class, 'remoteImages'])->name('incus.remote-images');
    Route::get('/incus/logs', [IncusController::class, 'logs'])->name('incus.logs');

    Route::get('/dokploy', [DokployController::class, 'index'])->name('dokploy.index');

    Route::get('/services', [ServicesController::class, 'index'])->name('services.index');
    Route::get('/logs', [LogsController::class, 'index'])->name('logs.index');
    Route::get('/logs/tail', [LogsController::class, 'tail'])->name('logs.tail');
    Route::get('/health', HealthController::class)->name('health.index');
    Route::get('/alerts', [AlertController::class, 'index'])->name('alerts.index');

    Route::middleware('can:operate')->group(function () {
        Route::post('/storage/zfs', [StorageController::class, 'action'])->name('storage.zfs');
        Route::post('/disks/test', [DiskController::class, 'selfTest'])->name('disks.test');
        Route::post('/disks/test/abort', [DiskController::class, 'abortSelfTest'])->name('disks.test.abort');
        Route::post('/updates/refresh', [UpdateController::class, 'refresh'])->name('updates.refresh');
        Route::post('/updates/upgrade', [UpdateController::class, 'upgrade'])->name('updates.upgrade');
        Route::post('/backups/jobs', [BackupController::class, 'store'])->name('backups.jobs.store');
        Route::put('/backups/jobs/{backupJob}', [BackupController::class, 'update'])->name('backups.jobs.update');
        Route::delete('/backups/jobs/{backupJob}', [BackupController::class, 'destroy'])->name('backups.jobs.destroy');
        Route::post('/backups/jobs/{backupJob}/toggle', [BackupController::class, 'toggle'])->name('backups.jobs.toggle');
        Route::post('/backups/jobs/{backupJob}/run', [BackupController::class, 'run'])->name('backups.jobs.run');
        Route::post('/backups/snapshots', [BackupController::class, 'snapshot'])->name('backups.snapshots.store');
        Route::post('/backups/snapshots/delete', [BackupController::class, 'deleteSnapshot'])->name('backups.snapshots.delete');
        Route::post('/backups/snapshots/restore', [BackupController::class, 'restoreSnapshot'])->name('backups.snapshots.restore');
        Route::post('/docker/action', [DockerController::class, 'action'])->name('docker.action');
        Route::post('/docker/devices', [DockerController::class, 'updateDevices'])->name('docker.devices');
        Route::get('/incus/detail', [IncusController::class, 'detail'])->name('incus.detail');
        Route::post('/incus/config', [IncusController::class, 'updateConfig'])->name('incus.config');
        Route::post('/incus/config/device', [IncusController::class, 'addDevice'])->name('incus.config.device.store');
        Route::post('/incus/config/device/set', [IncusController::class, 'updateDevice'])->name('incus.config.device.update');
        Route::post('/incus/config/device/remove', [IncusController::class, 'removeDevice'])->name('incus.config.device.destroy');
        Route::post('/incus/config/device/override', [IncusController::class, 'overrideDevice'])->name('incus.config.device.override');
        Route::post('/incus/action', [IncusController::class, 'action'])->name('incus.action');
        Route::post('/incus/create', [IncusController::class, 'create'])->name('incus.create');
        Route::post('/incus/delete', [IncusController::class, 'destroy'])->name('incus.delete');
        Route::post('/dokploy/projects', [DokployController::class, 'storeProject'])->name('dokploy.projects.store');
        Route::put('/dokploy/projects/{project}', [DokployController::class, 'updateProject'])
            ->where('project', '[A-Za-z0-9_-]{1,64}')
            ->name('dokploy.projects.update');
        Route::delete('/dokploy/projects/{project}', [DokployController::class, 'destroyProject'])
            ->where('project', '[A-Za-z0-9_-]{1,64}')
            ->name('dokploy.projects.destroy');
        Route::post('/dokploy/environments', [DokployController::class, 'storeEnvironment'])->name('dokploy.environments.store');
        Route::delete('/dokploy/environments/{environment}', [DokployController::class, 'destroyEnvironment'])
            ->where('environment', '[A-Za-z0-9_-]{1,64}')
            ->name('dokploy.environments.destroy');
        Route::post('/dokploy/applications', [DokployController::class, 'storeApplication'])->name('dokploy.applications.store');
        Route::post('/dokploy/resources/{type}/{resource}/action', [DokployController::class, 'resourceAction'])
            ->where('type', '[a-z]+')
            ->where('resource', '[A-Za-z0-9_-]{1,64}')
            ->name('dokploy.resources.action');
        Route::post('/services/action', [ServicesController::class, 'action'])->name('services.action');

        Route::post('/consoles', [ConsoleController::class, 'store'])->name('consoles.store');
        Route::get('/consoles/{session}', [ConsoleController::class, 'read'])
            ->where('session', '[a-f0-9]{32}')
            ->name('consoles.read');
        Route::post('/consoles/{session}/input', [ConsoleController::class, 'write'])
            ->where('session', '[a-f0-9]{32}')
            ->name('consoles.write');
        Route::post('/consoles/{session}/resize', [ConsoleController::class, 'resize'])
            ->where('session', '[a-f0-9]{32}')
            ->name('consoles.resize');
        Route::delete('/consoles/{session}', [ConsoleController::class, 'destroy'])
            ->where('session', '[a-f0-9]{32}')
            ->name('consoles.destroy');
    });

    Route::middleware('can:use-terminal')->group(function () {
        Route::get('/terminal', [TerminalController::class, 'index'])->name('terminal.index');
    });

    Route::middleware('can:manage-users')->group(function () {
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::put('/settings/dokploy', [ProfileController::class, 'updateDokploy'])->name('settings.dokploy.update');
        Route::put('/settings/hub', [ProfileController::class, 'updateHub'])->name('settings.hub.update');
        Route::post('/settings/hub/report', [ProfileController::class, 'reportHubNow'])->name('settings.hub.report');
        Route::post('/alerts/channels', [AlertController::class, 'store'])->name('alerts.channels.store');
        Route::put('/alerts/channels/{channel}', [AlertController::class, 'update'])->name('alerts.channels.update');
        Route::delete('/alerts/channels/{channel}', [AlertController::class, 'destroy'])->name('alerts.channels.destroy');
        Route::post('/alerts/channels/{channel}/toggle', [AlertController::class, 'toggle'])->name('alerts.channels.toggle');
        Route::post('/alerts/channels/{channel}/test', [AlertController::class, 'test'])->name('alerts.channels.test');
        Route::post('/alerts/run', [AlertController::class, 'run'])->name('alerts.run');
    });

    Route::get('/audit', [AuditLogController::class, 'index'])
        ->middleware('can:view-audit')
        ->name('audit.index');

    Route::get('/settings', [ProfileController::class, 'edit'])->name('settings.edit');
    Route::put('/settings/profile', [ProfileController::class, 'update'])->name('settings.profile');

    Route::redirect('/servers', '/system');
    Route::redirect('/deployments', '/docker');
});

Route::fallback(function (Request $request) {
    $matchesAnotherMethod = collect(Route::getRoutes()->getRoutes())
        ->contains(fn ($route) => ! $route->isFallback && $route->matches($request, false));

    abort($matchesAnotherMethod ? 405 : 404);
});
