<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::create([
            'name' => 'Admin',
            'email' => 'admin@gmail.com',
            'password' => Hash::make('admin'),
            'email_verified_at' => now(),
            'user_type' => 'admin',
            'subscription_level' => 'gold', // Setting highest level for admin
            'coins' => 1000, // Give admin some initial coins
            'balance' => 100.00, // Give admin some initial balance
        ]);
    }
} 