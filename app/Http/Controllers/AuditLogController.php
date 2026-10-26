<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = [
            'search' => $request->string('search')->trim()->value() ?: null,
            'action' => $request->string('action')->trim()->value() ?: null,
            'user' => $request->integer('user') ?: null,
        ];

        $logs = AuditLog::query()
            ->with('user:id,name,username')
            ->when($filters['search'], function ($query, string $search) {
                $query->where('description', 'like', "%{$search}%");
            })
            ->when($filters['action'], fn ($query, string $action) => $query->where('action', $action))
            ->when($filters['user'], fn ($query, int $userId) => $query->where('user_id', $userId))
            ->latest('created_at')
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        return Inertia::render('Audit/Index', [
            'logs' => $logs,
            'filters' => $filters,
            'actions' => AuditLog::query()->distinct()->orderBy('action')->pluck('action'),
            'users' => User::query()->orderBy('name')->get(['id', 'name', 'username']),
        ]);
    }
}
