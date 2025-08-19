<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\CoinTransaction;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TestUsersSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create Bronze User
        $bronzeUser = User::create([
            'name' => 'Bronze User',
            'email' => 'bronze@gmail.com',
            'password' => Hash::make('12345678'),
            'subscription_level' => 'bronze',
            'email_verified_at' => Carbon::now(),
            'coins' => 60 // This will be overridden by transactions
        ]);
        
        // Create Silver User
        $silverUser = User::create([
            'name' => 'Silver User',
            'email' => 'silver@gmail.com',
            'password' => Hash::make('12345678'),
            'subscription_level' => 'silver',
            'email_verified_at' => Carbon::now(),
            'coins' => 60 // This will be overridden by transactions
        ]);
        
        // Create Gold User
        $goldUser = User::create([
            'name' => 'Gold User',
            'email' => 'gold@gmail.com',
            'password' => Hash::make('12345678'),
            'subscription_level' => 'gold',
            'email_verified_at' => Carbon::now(),
            'coins' => 50 // This will be overridden by transactions
        ]);
        
        // Add coin transactions for each user
        $this->addCoins($bronzeUser->id, 60, 'Initial balance');
        $this->addCoins($silverUser->id, 60, 'Initial balance');
        $this->addCoins($goldUser->id, 50, 'Initial balance');
    }
    
    /**
     * Helper to add coins to a user via transaction
     */
    private function addCoins($userId, $amount, $notes)
    {
        CoinTransaction::create([
            'user_id' => $userId,
            'direction' => 'in',
            'amount' => $amount,
            'source_type' => 'system',
            'action' => 'initial_balance',
            'notes' => $notes
        ]);
    }
}
