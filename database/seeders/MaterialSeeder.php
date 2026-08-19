<?php

namespace Database\Seeders;

use App\Models\Material;
use Illuminate\Database\Seeder;

class MaterialSeeder extends Seeder
{
    public function run(): void
    {
        $materials = [
            [
                'name' => 'Bạc 925',
                'code' => 'silver_925',
                'purity' => '92.5%',
                'description' => 'Bạc 925 chứa 92.5% bạc nguyên chất.',
                'is_active' => true,
            ],
            [
                'name' => 'Bạc Ý',
                'code' => 'italian_silver',
                'purity' => '92.5%',
                'description' => 'Bạc Ý cao cấp dùng trong trang sức.',
                'is_active' => true,
            ],
            [
                'name' => 'Bạc Thái',
                'code' => 'thai_silver',
                'purity' => '92.5%',
                'description' => 'Bạc Thái với phong cách thiết kế đặc trưng.',
                'is_active' => true,
            ],
            [
                'name' => 'Bạc nguyên chất',
                'code' => 'pure_silver',
                'purity' => '99.9%',
                'description' => 'Bạc có độ tinh khiết cao.',
                'is_active' => true,
            ],
        ];

        foreach ($materials as $material) {
            Material::updateOrCreate(
                ['code' => $material['code']],
                $material
            );
        }
    }
}