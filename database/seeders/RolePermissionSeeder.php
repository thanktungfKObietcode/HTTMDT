<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            'admin' => 'Quản trị viên',
            'staff' => 'Nhân viên',
            'vendor' => 'Nhà cung cấp',
            'customer' => 'Khách hàng',
        ];

        foreach ($roles as $name => $label) {
            Role::firstOrCreate(['name' => $name], ['name' => $name, 'guard_name' => 'web']);
        }

        $permissions = [
            'products.view', 'products.create', 'products.update', 'products.delete',
            'orders.view', 'orders.create', 'orders.update', 'orders.delete',
            'customers.view', 'customers.create', 'customers.update', 'customers.delete',
            'settings.view', 'settings.update',
            'dashboard.view', 'orders.refund', 'coupons.manage', 'shipping.manage',
            'staff.manage', 'roles.manage',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission], ['name' => $permission, 'guard_name' => 'web']);
        }

        $adminRole = Role::where('name', 'admin')->first();
        $staffRole = Role::where('name', 'staff')->first();
        $vendorRole = Role::where('name', 'vendor')->first();
        $customerRole = Role::where('name', 'customer')->first();

        $adminRole->permissions()->sync(Permission::where('guard_name', 'web')->pluck('id'));
        $staffRole->permissions()->sync(Permission::whereIn('name', [
            'dashboard.view',
            'products.view',
            'orders.view',
            'customers.view',
            'settings.view',
        ])->pluck('id'));
        $vendorRole->permissions()->sync(Permission::whereIn('name', ['products.view', 'products.create', 'products.update'])->pluck('id'));
        $customerRole->permissions()->sync(Permission::whereIn('name', ['products.view'])->pluck('id'));
    }
}
