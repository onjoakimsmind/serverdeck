<?php

namespace App\Http\Controllers;

use App\Models\AlertChannel;
use App\Services\AlertService;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class AlertController extends Controller
{
    public function index(Request $request, AlertService $alerts): Response
    {
        return Inertia::render('Alerts/Index', [
            'alerts' => $alerts->overview($request->user()->isAdmin()),
        ]);
    }

    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        $channel = AlertChannel::create($this->validateChannel($request));

        $audit->log(
            'alert.channel.store',
            "{$request->user()->name} created alert channel {$channel->name}",
            $channel,
            $this->auditMeta($channel),
        );

        return back()->with('success', "Alert channel “{$channel->name}” was created.");
    }

    public function update(Request $request, AlertChannel $channel, AuditLogger $audit): RedirectResponse
    {
        $channel->update($this->validateChannel($request));

        $audit->log(
            'alert.channel.update',
            "{$request->user()->name} updated alert channel {$channel->name}",
            $channel,
            $this->auditMeta($channel),
        );

        return back()->with('success', "Alert channel “{$channel->name}” was updated.");
    }

    public function destroy(Request $request, AlertChannel $channel, AuditLogger $audit): RedirectResponse
    {
        $name = $channel->name;
        $channel->delete();

        $audit->log(
            'alert.channel.destroy',
            "{$request->user()->name} deleted alert channel {$name}",
            null,
            ['name' => $name, 'type' => $channel->type],
        );

        return back()->with('success', "Alert channel “{$name}” was deleted.");
    }

    public function toggle(Request $request, AlertChannel $channel, AuditLogger $audit): RedirectResponse
    {
        $channel->update(['enabled' => ! $channel->enabled]);

        $audit->log(
            'alert.channel.toggle',
            sprintf(
                '%s %s alert channel %s',
                $request->user()->name,
                $channel->enabled ? 'enabled' : 'disabled',
                $channel->name,
            ),
            $channel,
            ['enabled' => $channel->enabled],
        );

        return back()->with('success', sprintf(
            'Alert channel “%s” was %s.',
            $channel->name,
            $channel->enabled ? 'enabled' : 'disabled',
        ));
    }

    public function test(Request $request, AlertChannel $channel, AlertService $alerts, AuditLogger $audit): RedirectResponse
    {
        try {
            $alerts->sendTest($channel);
        } catch (Throwable $exception) {
            $audit->log(
                'alert.channel.test',
                "{$request->user()->name} tested alert channel {$channel->name} and delivery failed",
                $channel,
                ['type' => $channel->type, 'success' => false],
            );

            return back()->with('error', "Test delivery failed: {$exception->getMessage()}");
        }

        $audit->log(
            'alert.channel.test',
            "{$request->user()->name} tested alert channel {$channel->name}",
            $channel,
            ['type' => $channel->type, 'success' => true],
        );

        return back()->with('success', "Test notification sent through “{$channel->name}”.");
    }

    public function run(Request $request, AlertService $alerts, AuditLogger $audit): RedirectResponse
    {
        $counts = $alerts->evaluate();

        $audit->log(
            'alerts.evaluate',
            "{$request->user()->name} evaluated host alerts",
            null,
            $counts,
        );

        return back()->with('success', sprintf(
            'Evaluated %d checks · %d triggered · %d recovered · %d failed.',
            $counts['evaluated'],
            $counts['triggered'],
            $counts['recovered'],
            $counts['failed'],
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function validateChannel(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'type' => ['required', Rule::in(AlertChannel::TYPES)],
            'destination' => ['required', 'string', 'max:2000'],
            'enabled' => ['boolean'],
            'notify_warning' => ['boolean'],
            'notify_critical' => ['boolean'],
            'notify_recovery' => ['boolean'],
        ]);

        $destination = trim($validated['destination']);

        if ($validated['type'] === AlertChannel::TYPE_EMAIL) {
            $recipients = collect(explode(',', $destination))
                ->map(fn (string $email) => trim($email))
                ->filter()
                ->values();

            if ($recipients->isEmpty()) {
                throw ValidationException::withMessages([
                    'destination' => 'Add at least one email recipient.',
                ]);
            }

            $invalid = $recipients->first(
                fn (string $email) => filter_var($email, FILTER_VALIDATE_EMAIL) === false,
            );

            if ($invalid !== null) {
                throw ValidationException::withMessages([
                    'destination' => "“{$invalid}” is not a valid email address.",
                ]);
            }

            $destination = $recipients->implode(', ');
        } else {
            $parts = parse_url($destination);

            if (! is_array($parts)
                || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)
                || empty($parts['host'])) {
                throw ValidationException::withMessages([
                    'destination' => 'Enter a valid HTTP or HTTPS webhook URL.',
                ]);
            }
        }

        $validated['destination'] = $destination;
        $validated['enabled'] = $request->boolean('enabled');
        $validated['notify_warning'] = $request->boolean('notify_warning');
        $validated['notify_critical'] = $request->boolean('notify_critical');
        $validated['notify_recovery'] = $request->boolean('notify_recovery');

        if (! $validated['notify_warning'] && ! $validated['notify_critical'] && ! $validated['notify_recovery']) {
            throw ValidationException::withMessages([
                'notify_warning' => 'Choose at least one event to deliver.',
            ]);
        }

        return $validated;
    }

    /**
     * @return array<string, mixed>
     */
    private function auditMeta(AlertChannel $channel): array
    {
        return [
            'name' => $channel->name,
            'type' => $channel->type,
            'enabled' => $channel->enabled,
            'notify_warning' => $channel->notify_warning,
            'notify_critical' => $channel->notify_critical,
            'notify_recovery' => $channel->notify_recovery,
        ];
    }
}
