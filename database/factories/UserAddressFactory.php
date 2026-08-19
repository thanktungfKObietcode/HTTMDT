<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\UserAddress>
 */
class UserAddressFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'full_name' => $this->faker->name(),
            'phone' => $this->faker->phoneNumber(),
            'province' => 'Hà Nội',
            'district' => 'Hoàn Kiếm',
            'ward' => 'Tràng Tiền',
            'address_line' => $this->faker->address(),
            'is_default' => false,
            'is_active' => true,
        ];
    }
}
