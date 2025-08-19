<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Call the TestUsersSeeder to create test users with specific subscription levels
        $this->call(TestUsersSeeder::class);

        $this->call([
            AdminSeeder::class,
            PaymentQrCodeSeeder::class,
        ]);
    }
}
