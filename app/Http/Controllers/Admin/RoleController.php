<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class RoleController extends Controller
{
    public function index(Request $request): View
    {
        $roles = Role::withCount(['users', 'permissions'])
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = '%' . trim($request->string('q')->toString()) . '%';
                $query->where('name', 'like', $term);
            })
            ->orderBy('name')
            ->paginate(15)
            ->appends($request->query());

        return view('admin.roles.index', [
            'pageTitle' => 'Quản lý vai trò (Roles)',
            'roles'     => $roles,
        ]);
    }

    public function show(Role $role): View
    {
        $role->load([
            'permissions',
            'users' => function ($query) {
                $query->latest()->take(50);
            }
        ]);

        return view('admin.roles.show', [
            'pageTitle' => 'Chi tiết vai trò',
            'role'      => $role,
            'allPermissions' => Permission::query()->where('guard_name', 'web')->orderBy('name')->get(),
        ]);
    }

    public function updatePermissions(Request $request, Role $role): RedirectResponse
    {
        $validated = $request->validate([
            'permissions' => ['nullable', 'array'],
            'permissions.*' => [
                'integer',
                'distinct',
                Rule::exists('permissions', 'id')->where(fn ($query) => $query->where('guard_name', 'web')),
            ],
        ]);

        DB::transaction(function () use ($role, $validated): void {
            $lockedRole = Role::query()->whereKey($role->id)->lockForUpdate()->firstOrFail();
            $permissionIds = $validated['permissions'] ?? [];

            if ($permissionIds !== []) {
                Permission::query()->whereIn('id', $permissionIds)->orderBy('id')->lockForUpdate()->get();
            }

            $lockedRole->permissions()->sync($permissionIds);
        });

        return back()->with('success', 'Quyền của vai trò đã được cập nhật.');
    }
}
