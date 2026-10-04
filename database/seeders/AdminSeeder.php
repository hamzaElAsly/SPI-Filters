<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $name = env('ADMIN_NAME');
        $email = env('ADMIN_EMAIL');
        $password = env('ADMIN_PASSWORD');

        if (! $name || ! $email || ! $password) {
            throw new InvalidArgumentException('ADMIN_NAME, ADMIN_EMAIL et ADMIN_PASSWORD doivent être définis dans .env.');
        }

        User::updateOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => Hash::make($password), 'role' => 'ADMIN', 'email_verified_at' => now()],
        );
    }
}
