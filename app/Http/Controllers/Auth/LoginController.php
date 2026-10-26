<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\Host\HostMetricsService;
use App\Services\Host\LinuxAccountProvisioner;
use App\Services\Host\PamAuthenticator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class LoginController extends Controller
{
    public function create(HostMetricsService $metrics): Response
    {
        return Inertia::render('Auth/Login', [
            'host' => $metrics->identity(),
        ]);
    }

    public function store(Request $request, PamAuthenticator $pam, LinuxAccountProvisioner $provisioner, AuditLogger $audit): RedirectResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string', 'max:32', 'regex:/^[a-z_][a-z0-9_.-]*$/'],
            'password' => ['required', 'string', 'max:1024'],
        ]);

        $username = Str::lower($credentials['username']);
        $key = $username.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);

            throw ValidationException::withMessages([
                'username' => "Too many sign-in attempts. Try again in {$seconds} seconds.",
            ]);
        }

        $account = $pam->authenticate($username, $credentials['password']);

        if (! $account) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages([
                'username' => 'The host did not accept that Linux username and password.',
            ]);
        }

        RateLimiter::clear($key);

        $user = $provisioner->provision($account);

        if (! $user->is_active) {
            $audit->log('auth.blocked', "{$username} attempted to sign in but the ServerDeck account is disabled", null, ['username' => $username], $user);

            throw ValidationException::withMessages([
                'username' => 'This Linux account has been disabled for ServerDeck access.',
            ]);
        }

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        $user->forceFill(['last_seen_at' => now()])->saveQuietly();
        $audit->log('auth.login', "{$user->name} signed in with Linux account {$username}", $user);

        return redirect()
            ->intended(route('dashboard'))
            ->with('success', 'Signed in as '.$user->name.'.');
    }

    public function destroy(Request $request, AuditLogger $audit): RedirectResponse
    {
        $user = $request->user();
        $audit->log('auth.logout', "{$user->name} signed out");

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
