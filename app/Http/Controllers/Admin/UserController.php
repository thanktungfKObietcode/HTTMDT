<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        $actor = $request->user();
        $query = User::with('roles');

        if (! $actor->hasRole('admin')) {
            $query->whereDoesntHave('roles', fn ($roleQuery) => $roleQuery->whereIn('name', ['admin', 'vendor']));

            if (! $actor->hasPermission('customers.view')) {
                $query->whereHas('roles', fn ($roleQuery) => $roleQuery->where('name', 'staff'));
            } elseif (! $actor->hasPermission('staff.manage')) {
                $query->whereDoesntHave('roles', fn ($roleQuery) => $roleQuery->where('name', 'staff'));
            }
        }

        $users = $query
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = '%' . trim($request->string('q')->toString()) . '%';
                $query->where(fn ($builder) => $builder->where('name', 'like', $term)->orWhere('email', 'like', $term));
            })
            ->when($request->filled('role'), fn ($query) => $query->whereHas('roles', fn ($roleQuery) => $roleQuery->where('name', $request->input('role'))))
            ->when($request->filled('status'), fn ($query) => $query->where('is_active', $request->input('status') === 'active'))
            ->latest()
            ->paginate(15)
            ->appends($request->query());

        return view('admin.users.index', [
            'pageTitle' => 'Quản lý người dùng',
            'users' => $users,
            'roles' => Role::orderBy('name')->get(),
        ]);
    }

    public function show(User $user): View
    {
        $this->assertCanViewUser(request()->user(), $user);

        $user->load([
            'roles',
            'addresses' => fn ($query) => $query->where('is_active', true)->latest(),
            'orders' => fn ($query) => $query->latest(),
            'reviews.product',
        ]);

        return view('admin.users.show', [
            'pageTitle' => 'Chi tiết người dùng',
            'user' => $user,
            'orderTotal' => $user->orders->sum(fn ($order) => (float) $order->total_amount),
            'assignableRoles' => $this->assignableRoles(request()->user(), $user),
        ]);
    }

    public function updateRoles(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('roles', 'id')->where(fn ($query) => $query->where('guard_name', 'web')),
            ],
        ]);

        DB::transaction(function () use ($request, $user, $validated): void {
            $adminRole = Role::query()
                ->where('name', 'admin')
                ->where('guard_name', 'web')
                ->lockForUpdate()
                ->firstOrFail();
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $requestedRoles = Role::query()
                ->whereIn('id', $validated['roles'])
                ->where('guard_name', 'web')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($requestedRoles->count() !== count($validated['roles'])) {
                throw ValidationException::withMessages(['roles' => 'Vai trò không hợp lệ.']);
            }

            $actor = $request->user();
            $lockedUser->load('roles');
            $currentlyAdmin = $lockedUser->hasRole('admin');
            $willBeAdmin = $requestedRoles->contains('name', 'admin');

            if (! $actor->hasRole('admin')) {
                if ($currentlyAdmin || $lockedUser->hasRole('vendor')) {
                    abort(403);
                }

                if ($requestedRoles->pluck('name')->diff(['customer', 'staff'])->isNotEmpty()) {
                    abort(403);
                }
            }

            if ((int) $lockedUser->id === (int) $actor->id && $currentlyAdmin && ! $willBeAdmin) {
                throw ValidationException::withMessages([
                    'roles' => 'Bạn không thể tự gỡ vai trò admin của mình.',
                ]);
            }

            if ($lockedUser->is_active && $currentlyAdmin && ! $willBeAdmin
                && $this->activeAdminCount($adminRole) <= 1) {
                throw ValidationException::withMessages([
                    'roles' => 'Không thể gỡ vai trò của admin đang hoạt động cuối cùng.',
                ]);
            }

            $lockedUser->roles()->sync($requestedRoles->modelKeys());
        });

        return back()->with('success', 'Vai trò người dùng đã được cập nhật.');
    }

    public function deactivate(User $user): RedirectResponse
    {
        DB::transaction(function () use ($user): void {
            $adminRole = Role::query()
                ->where('name', 'admin')
                ->where('guard_name', 'web')
                ->lockForUpdate()
                ->firstOrFail();
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            if ((int) $lockedUser->id === (int) auth()->id()) {
                throw ValidationException::withMessages([
                    'status' => 'Bạn không thể tự vô hiệu hóa tài khoản đang đăng nhập.',
                ]);
            }

            $this->assertCanChangeUserStatus(request()->user(), $lockedUser);

            if ($lockedUser->is_active && $lockedUser->hasRole('admin')
                && $this->activeAdminCount($adminRole) <= 1) {
                throw ValidationException::withMessages([
                    'status' => 'Không thể vô hiệu hóa admin đang hoạt động cuối cùng.',
                ]);
            }

            $lockedUser->update(['is_active' => false]);
        });

        return back()->with('success', 'Người dùng đã được vô hiệu hóa.');
    }

    public function activate(User $user): RedirectResponse
    {
        DB::transaction(function () use ($user): void {
            Role::query()
                ->where('name', 'admin')
                ->where('guard_name', 'web')
                ->lockForUpdate()
                ->firstOrFail();
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            $this->assertCanChangeUserStatus(request()->user(), $lockedUser);
            $lockedUser->update(['is_active' => true]);
        });

        return back()->with('success', 'Người dùng đã được kích hoạt.');
    }

    private function assertCanViewUser(User $actor, User $target): void
    {
        $target->loadMissing('roles');

        if ($actor->hasRole('admin')) {
            return;
        }

        if ($target->hasAnyRole(['admin', 'vendor'])) {
            abort(403);
        }

        if ($target->hasRole('staff') && ! $actor->hasPermission('staff.manage')) {
            abort(403);
        }

        if (! $target->hasRole('staff') && ! $actor->hasPermission('customers.view')) {
            abort(403);
        }
    }

    private function assertCanChangeUserStatus(User $actor, User $target): void
    {
        $target->loadMissing('roles');

        if ($actor->hasRole('admin')) {
            return;
        }

        if ($target->hasAnyRole(['admin', 'vendor'])) {
            abort(403);
        }

        $requiredPermission = $target->hasRole('staff') ? 'staff.manage' : 'customers.update';

        if (! $actor->hasPermission($requiredPermission)) {
            abort(403);
        }
    }

    private function assignableRoles(User $actor, User $target)
    {
        if ($actor->hasRole('admin')) {
            return Role::query()->where('guard_name', 'web')->orderBy('name')->get();
        }

        if ($actor->hasPermission('staff.manage') && ! $target->hasAnyRole(['admin', 'vendor'])) {
            return Role::query()
                ->where('guard_name', 'web')
                ->whereIn('name', ['customer', 'staff'])
                ->orderBy('name')
                ->get();
        }

        return collect();
    }

    private function activeAdminCount(Role $adminRole): int
    {
        return $adminRole->users()->where('users.is_active', true)->count();
    }
}
