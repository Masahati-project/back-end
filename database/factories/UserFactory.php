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
            'full_name'           => fake()->name(),
            'phone'               => '05' . fake()->numerify('########'),
            'email'               => fake()->unique()->safeEmail(),
            'verified_at'         => now(),
            'password'            => static::$password ??= Hash::make('password'),
            'remember_token'      => Str::random(10),
            'role'                => 'customer',
            'status'              => 'active',
            'profile_picture_url' => null,
            'proof_document_url'  => null,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'verified_at' => null,
        ]);
    }
}
