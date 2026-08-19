<?php

namespace Database\Seeders;

use App\Models\ShippingMethod;
use Illuminate\Database\Seeder;

class ShippingMethodSeeder extends Seeder
{
    public function run(): void
    {
        $methods = [
            ['name' => 'Tiêu chuẩn', 'code' => 'standard', 'description' => 'Giao hàng tiêu chuẩn 3-5 ngày', 'base_fee' => 30000, 'fee_per_km' => 1000, 'estimated_days_min' => 3, 'estimated_days_max' => 5],
            ['name' => 'Nhanh', 'code' => 'fast', 'description' => 'Giao hàng nhanh 1-2 ngày', 'base_fee' => 60000, 'fee_per_km' => 2000, 'estimated_days_min' => 1, 'estimated_days_max' => 2],
            ['name' => 'Nhận tại showroom', 'code' => 'pickup', 'description' => 'Nhận hàng trực tiếp tại showroom', 'base_fee' => 0, 'fee_per_km' => 0, 'estimated_days_min' => 1, 'estimated_days_max' => 1],
        ];

        foreach ($methods as $method) {
            ShippingMethod::firstOrCreate(['code' => $method['code']], $method);
        }
    }
}
