<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name'   => $this->faker->firstName(),
            'last_name'    => $this->faker->lastName(),
            'display_name' => $this->faker->userName(), // ✅ required column
            'email'        => $this->faker->unique()->safeEmail(),
            'password_hash'=> Hash::make('password'),
            'status'       => 'active',
            'channel_id'   => null, // optional, can be assigned later
            'preferred_language_id' => null,
            'timezone'     => 'Europe/London',
            'is_email_verified' => true,
            'is_phone_verified' => false,
        ];
        // return [
        //     'first_name' => $this->faker->firstName(),
        //     'last_name' => $this->faker->lastName(),
        //     'display_name' => $this->faker->userName(), // <- add this
        //     'email' => $this->faker->unique()->safeEmail(),
        //     'email_verified_at' => now(),
        //     'password_hash' => static::$password ??= Hash::make('password'),
        //     // 'remember_token' => Str::random(10),
        //     'status' => 'active', // optional, depending on your schema
        // ];
    }


    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
