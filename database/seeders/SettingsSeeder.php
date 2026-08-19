<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            ['key' => 'app_name', 'value' => 'Silver Jewelry', 'group' => 'general'],
            ['key' => 'app_email', 'value' => 'hello@silverjewelry.vn', 'group' => 'general'],
            ['key' => 'app_phone', 'value' => '0900000000', 'group' => 'general'],
            ['key' => 'default_currency', 'value' => 'VND', 'group' => 'general'],
            ['key' => 'free_shipping_threshold', 'value' => '2000000', 'group' => 'shipping'],
            ['key' => 'vnpay_enabled', 'value' => '1', 'group' => 'payment'],
            ['key' => 'momo_enabled', 'value' => '1', 'group' => 'payment'],
            ['key' => 'zalopay_enabled', 'value' => '1', 'group' => 'payment'],
            ['key' => 'email_order_confirmation', 'value' => '1', 'group' => 'email'],
            ['key' => 'email_shipping_notification', 'value' => '1', 'group' => 'email'],
        ];

        foreach ($settings as $setting) {
            Setting::firstOrCreate(['key' => $setting['key']], $setting);
        }
    }
}
