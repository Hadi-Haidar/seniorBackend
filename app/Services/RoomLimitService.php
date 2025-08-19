<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserRoomUsage;
use App\Models\CoinTransaction;
use Carbon\Carbon;

class RoomLimitService
{
    const ADDITIONAL_ROOM_COST = 50; // coins

    /**
     * Get monthly room limit based on user subscription level
     */
    public function getMonthlyRoomLimit(User $user): int
    {
        $subscriptionLevel = $user->getEffectiveSubscriptionLevel();
        
        return match(strtolower($subscriptionLevel)) {
            'bronze' => 2,
            'silver', 'gold' => 4,
            default => 2 // Default to bronze limits
        };
    }

    /**
     * Get current month's room usage for user
     */
    public function getCurrentMonthUsage(User $user): int
    {
        $now = Carbon::now();
        
        return UserRoomUsage::where('user_id', $user->id)
            ->where('usage_year', $now->year)
            ->where('usage_month', $now->month)
            ->sum('monthly_rooms_created');
    }

    /**
     * Check if user can create a room (within free limit or has enough coins)
     */
    public function canCreateRoom(User $user): array
    {
        $monthlyLimit = $this->getMonthlyRoomLimit($user);
        $currentUsage = $this->getCurrentMonthUsage($user);
        $isWithinFreeLimit = $currentUsage < $monthlyLimit;

        if ($isWithinFreeLimit) {
            return [
                'can_create' => true,
                'is_free' => true,
                'rooms_used' => $currentUsage,
                'monthly_limit' => $monthlyLimit,
                'additional_cost' => 0
            ];
        }

        // Check if user has enough coins for additional room
        $hasEnoughCoins = $user->coins >= self::ADDITIONAL_ROOM_COST;

        return [
            'can_create' => $hasEnoughCoins,
            'is_free' => false,
            'rooms_used' => $currentUsage,
            'monthly_limit' => $monthlyLimit,
            'additional_cost' => self::ADDITIONAL_ROOM_COST,
            'user_coins' => $user->coins,
            'insufficient_coins' => !$hasEnoughCoins
        ];
    }

    /**
     * Process room creation (deduct coins if needed and update usage)
     */
    public function processRoomCreation(User $user): array
    {
        $canCreate = $this->canCreateRoom($user);
        
        if (!$canCreate['can_create']) {
            throw new \Exception('Insufficient coins to create additional room. You need ' . self::ADDITIONAL_ROOM_COST . ' coins.');
        }

        $now = Carbon::now();
        
        // Get or create usage record for current month
        $usage = UserRoomUsage::firstOrCreate([
            'user_id' => $user->id,
            'usage_year' => $now->year,
            'usage_month' => $now->month
        ], [
            'monthly_rooms_created' => 0
        ]);

        // Increment room count
        $usage->increment('monthly_rooms_created');

        // If not free, deduct coins and create transaction
        if (!$canCreate['is_free']) {
            // Attempt to deduct coins
            $deductionSuccessful = $user->deductCoins(self::ADDITIONAL_ROOM_COST);
            
            if (!$deductionSuccessful) {
                throw new \Exception('Failed to deduct coins. Insufficient balance.');
            }
            
            // Record coin transaction
            CoinTransaction::create([
                'user_id' => $user->id,
                'direction' => 'out',
                'amount' => self::ADDITIONAL_ROOM_COST,
                'source_type' => 'spend',
                'action' => 'room_creation',
                'notes' => 'Additional room creation (beyond monthly limit)'
            ]);
        }

        return [
            'cost_deducted' => !$canCreate['is_free'],
            'coins_spent' => !$canCreate['is_free'] ? self::ADDITIONAL_ROOM_COST : 0,
            'remaining_coins' => $user->fresh()->coins,
            'rooms_used_this_month' => $usage->monthly_rooms_created
        ];
    }

    /**
     * Get room usage summary for user
     */
    public function getRoomUsageSummary(User $user): array
    {
        $monthlyLimit = $this->getMonthlyRoomLimit($user);
        $currentUsage = $this->getCurrentMonthUsage($user);
        $remainingFree = max(0, $monthlyLimit - $currentUsage);

        return [
            'subscription_level' => $user->getEffectiveSubscriptionLevel(),
            'monthly_limit' => $monthlyLimit,
            'rooms_used_this_month' => $currentUsage,
            'remaining_free_rooms' => $remainingFree,
            'additional_room_cost' => self::ADDITIONAL_ROOM_COST,
            'user_coins' => $user->coins,
            'can_create_free' => $remainingFree > 0,
            'can_create_paid' => $user->coins >= self::ADDITIONAL_ROOM_COST
        ];
    }
} 