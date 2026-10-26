<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = [
            'search' => $request->string('search')->trim()->value() ?: null,
            'role' => in_array($request->input('role'), array_column(UserRole::cases(), 'value'), true)
                ? $request->input('role')
                : null,
        ];

        $users = User::query()
            ->when($filters['search'], function ($query, string $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('username', 'like', "%{$search}%")
                        ->orWhere('title', 'like', "%{$search}%");
                });
            })
            ->when($filters['role'], fn ($query, string $role) => $query->where('role', $role))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        $roleCounts = User::query()
            ->selectRaw('role, count(*) as aggregate')
            ->groupBy('role')
            ->pluck('aggregate', 'role');

        return Inertia::render('Users/Index', [
            'users' => $users,
            'filters' => $filters,
            'roles' => $this->roles(),
            'counts' => [
                'all' => (int) $roleCounts->sum(),
                'admin' => (int) ($roleCounts['admin'] ?? 0),
                'operator' => (int) ($roleCounts['operator'] ?? 0),
                'viewer' => (int) ($roleCounts['viewer'] ?? 0),
                'active' => User::query()->where('is_active', true)->count(),
            ],
        ]);
    }

    public function update(Request $request, User $user, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'title' => ['nullable', 'string', 'max:100'],
            'role' => ['required', Rule::in(array_column(UserRole::cases(), 'value'))],
            'is_active' => ['required', 'boolean'],
        ]);

        if ($user->id === $request->user()->id) {
            if ($validated['role'] !== $user->role->value) {
                throw ValidationException::withMessages(['role' => 'You cannot change your own role.']);
            }

            if (! $validated['is_active']) {
                throw ValidationException::withMessages(['is_active' => 'You cannot disable your own access.']);
            }
        }

        $losesAdmin = $user->isAdmin() && ($validated['role'] !== 'admin' || ! $validated['is_active']);

        if ($losesAdmin && User::query()->where('role', 'admin')->where('is_active', true)->count() <= 1) {
            throw ValidationException::withMessages(['role' => 'At least one active administrator must remain.']);
        }

        $user->update([
            'name' => $validated['name'],
            'title' => $validated['title'],
            'role' => $validated['role'],
            'is_active' => $validated['is_active'],
        ]);

        $audit->log(
            'user.updated',
            "{$request->user()->name} updated {$user->name}'s ServerDeck access",
            $user,
            ['role' => $user->role->value, 'is_active' => $user->is_active],
        );

        return back()->with('success', "{$user->name}'s access was updated.");
    }

    private function roles()
    {
        return collect(UserRole::cases())
            ->map(fn (UserRole $role) => [
                'value' => $role->value,
                'label' => $role->label(),
                'description' => $role->description(),
            ])
            ->values();
    }
}
