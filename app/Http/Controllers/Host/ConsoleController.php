<?php

namespace App\Http\Controllers\Host;

use App\Exceptions\ConsoleException;
use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\ConsoleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ConsoleController extends Controller
{
    public function store(Request $request, ConsoleService $consoles, AuditLogger $audit): JsonResponse
    {
        $validated = $request->validate([
            'target' => ['required', Rule::in(['incus', 'docker', 'host'])],
            'kind' => ['required', Rule::in(['container', 'virtual-machine', 'host'])],
            'mode' => ['required', Rule::in(['shell', 'serial'])],
            'name' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.:-]*$/'],
            'cols' => ['required', 'integer', 'min:20', 'max:400'],
            'rows' => ['required', 'integer', 'min:5', 'max:200'],
        ]);

        if ($validated['target'] === 'host' && ! $request->user()->canUseTerminal()) {
            abort(403, 'Only administrators can open the host terminal.');
        }

        if ($validated['target'] === 'host' && $validated['kind'] !== 'host') {
            throw ValidationException::withMessages([
                'kind' => 'Host consoles must use the host kind.',
            ]);
        }

        if ($validated['target'] === 'host' && $validated['mode'] !== 'shell') {
            throw ValidationException::withMessages([
                'mode' => 'The host console only provides a shell.',
            ]);
        }

        if ($validated['target'] === 'docker' && $validated['kind'] !== 'container') {
            throw ValidationException::withMessages([
                'kind' => 'Docker consoles are only available for containers.',
            ]);
        }

        if ($validated['target'] === 'docker' && $validated['mode'] !== 'shell') {
            throw ValidationException::withMessages([
                'mode' => 'Docker consoles only provide shells.',
            ]);
        }

        if ($validated['mode'] === 'serial' && $validated['kind'] !== 'virtual-machine') {
            throw ValidationException::withMessages([
                'mode' => 'Serial consoles are only available for virtual machines.',
            ]);
        }

        try {
            $console = $consoles->open(
                $request->user()->id,
                $validated['target'],
                $validated['kind'],
                $validated['mode'],
                $validated['name'],
                $validated['cols'],
                $validated['rows'],
            );
        } catch (\Throwable $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $audit->log(
            'console.open',
            "{$request->user()->name} opened a console to ".$this->label(
                $validated['target'],
                $validated['kind'],
                $validated['mode'],
                $validated['name'],
            ),
            null,
            [
                'target' => $validated['target'],
                'kind' => $validated['kind'],
                'mode' => $validated['mode'],
                'name' => $validated['name'],
            ],
        );

        return response()->json($console);
    }

    public function read(Request $request, string $session, ConsoleService $consoles): JsonResponse
    {
        $offset = max(0, $request->integer('offset', 0));

        try {
            return response()->json($consoles->read($request->user()->id, $session, $offset));
        } catch (ConsoleException $exception) {
            return response()->json(['message' => $exception->getMessage()], 410);
        } catch (\Throwable $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function write(Request $request, string $session, ConsoleService $consoles): JsonResponse
    {
        $validated = $request->validate([
            'data' => ['required', 'string', 'max:524288'],
        ]);

        try {
            return response()->json($consoles->write($request->user()->id, $session, $validated['data']));
        } catch (ConsoleException $exception) {
            return response()->json(['message' => $exception->getMessage()], 410);
        } catch (\Throwable $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function resize(Request $request, string $session, ConsoleService $consoles): JsonResponse
    {
        $validated = $request->validate([
            'cols' => ['required', 'integer', 'min:20', 'max:400'],
            'rows' => ['required', 'integer', 'min:5', 'max:200'],
        ]);

        try {
            return response()->json($consoles->resize(
                $request->user()->id,
                $session,
                $validated['cols'],
                $validated['rows'],
            ));
        } catch (ConsoleException $exception) {
            return response()->json(['message' => $exception->getMessage()], 410);
        } catch (\Throwable $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function destroy(Request $request, string $session, ConsoleService $consoles, AuditLogger $audit): JsonResponse
    {
        $meta = $consoles->close($request->user()->id, $session);

        if ($meta !== null) {
            $audit->log(
                'console.close',
                "{$request->user()->name} closed a console to ".$this->label(
                    (string) ($meta['target'] ?? ''),
                    (string) ($meta['kind'] ?? ''),
                    (string) ($meta['mode'] ?? 'shell'),
                    (string) ($meta['name'] ?? ''),
                ),
                null,
                [
                    'target' => $meta['target'] ?? null,
                    'kind' => $meta['kind'] ?? null,
                    'mode' => $meta['mode'] ?? null,
                    'name' => $meta['name'] ?? null,
                ],
            );
        }

        return response()->json(['closed' => true]);
    }

    private function label(string $target, string $kind, string $mode, string $name): string
    {
        if ($target === 'host') {
            return "Host root shell ({$name})";
        }

        if ($target === 'docker') {
            return "Docker container {$name}";
        }

        if ($kind === 'virtual-machine') {
            return $mode === 'serial'
                ? "Incus VM serial console {$name}"
                : "Incus VM shell {$name}";
        }

        return "Incus container {$name}";
    }
}
