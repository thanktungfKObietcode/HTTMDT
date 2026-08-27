<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use Illuminate\Http\Request;
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
        ]);
    }
}
