<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['guard_name' => 'web']);
        $customerRole = Role::firstOrCreate(['name' => 'customer'], ['guard_name' => 'web']);

        $admin = User::firstOrCreate(
            ['email' => 'admin@silverjewelry.vn'],
            [
                'name' => 'Admin Silver',
                'password' => Hash::make('password'),
                'phone' => '0900000001',
                'is_active' => true,
                'receive_newsletter' => true,
            ]
        );
        $admin->roles()->syncWithoutDetaching([$adminRole->id]);

        $customers = [
            ['name' => 'Nguyễn Văn A', 'email' => 'customer1@silverjewelry.vn', 'phone' => '0900000002'],
            ['name' => 'Trần Thị B', 'email' => 'customer2@silverjewelry.vn', 'phone' => '0900000003'],
            ['name' => 'Lê Văn C', 'email' => 'customer3@silverjewelry.vn', 'phone' => '0900000004'],
        ];

        foreach ($customers as $item) {
            $user = User::firstOrCreate(
                ['email' => $item['email']],
                [
                    'name' => $item['name'],
                    'password' => Hash::make('password'),
                    'phone' => $item['phone'],
                    'is_active' => true,
                    'receive_newsletter' => true,
                ]
            );
            $user->roles()->syncWithoutDetaching([$customerRole->id]);
        }
    }
}
