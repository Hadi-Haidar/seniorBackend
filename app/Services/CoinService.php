<?php

namespace App\Services;

use App\Models\CoinTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class CoinService
{
    /**
     * Grant registration reward to a new user
     * This should be called automatically when a user registers
     */
    public static function grantRegistrationReward(User $user): bool
    {
        try {
            // Check if user already has registration reward
            $alreadyGranted = CoinTransaction::where('user_id', $user->id)
                ->where('source_type', 'reward')
                ->where('action', 'first_registration')
                ->exists();

            if ($alreadyGranted) {
                return false; // Already granted
            }

            DB::beginTransaction();

            // Add coins to user
            $rewardAmount = 15;
            $user->addCoins($rewardAmount);

            // Record the transaction
            CoinTransaction::create([
                'user_id' => $user->id,
                'direction' => 'in',
                'amount' => $rewardAmount,
                'source_type' => 'reward',
                'action' => 'first_registration',
                'notes' => 'Welcome bonus for new user registration (auto-granted)'
            ]);

            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Failed to grant registration reward to user ' . $user->id . ': ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if user can claim daily login reward
     */
    public static function canClaimDailyLogin(User $user): bool
    {
        $today = Carbon::today();
        
        return !CoinTransaction::where('user_id', $user->id)
            ->where('source_type', 'reward')
            ->where('action', 'daily_login')
            ->whereDate('created_at', $today)
            ->exists();
    }

    /**
     * Check if user can claim activity reward
     */
    public static function canClaimActivity(User $user): bool
    {
        $today = Carbon::today();
        
        return !CoinTransaction::where('user_id', $user->id)
            ->where('source_type', 'reward')
            ->where('action', 'activity_reward')
            ->whereDate('created_at', $today)
            ->exists();
    }

    /**
     * Get activity reward amount based on subscription level
     */
    public static function getActivityRewardAmount(User $user): int
    {
        return 10; // Fixed amount for all subscription levels
    }

    /**
     * Grant coins to user with transaction record
     */
    public static function grantCoins(User $user, int $amount, string $action, string $sourceType = 'system', string $notes = null): bool
    {
        try {
            DB::beginTransaction();

            // Add coins to user
            $user->addCoins($amount);

            // Record the transaction
            CoinTransaction::create([
                'user_id' => $user->id,
                'direction' => 'in',
                'amount' => $amount,
                'source_type' => $sourceType,
                'action' => $action,
                'notes' => $notes ?: "Granted {$amount} coins for {$action}"
            ]);

            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Failed to grant coins to user ' . $user->id . ': ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Deduct coins from user with transaction record
     */
    public static function deductCoins(User $user, int $amount, string $action, string $notes = null): bool
    {
        try {
            // Check if user has enough coins
            if ($user->coins < $amount) {
                return false;
            }

            DB::beginTransaction();

            // Deduct coins from user
            $user->deductCoins($amount);

            // Record the transaction
            CoinTransaction::create([
                'user_id' => $user->id,
                'direction' => 'out',
                'amount' => $amount,
                'source_type' => 'spend',
                'action' => $action,
                'notes' => $notes ?: "Spent {$amount} coins on {$action}"
            ]);

            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Failed to deduct coins from user ' . $user->id . ': ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get user's coin statistics
     */
    public static function getUserCoinStats(User $user): array
    {
        $totalEarned = CoinTransaction::where('user_id', $user->id)
            ->where('direction', 'in')
            ->sum('amount');

        $totalSpent = CoinTransaction::where('user_id', $user->id)
            ->where('direction', 'out')
            ->sum('amount');

        return [
            'current_balance' => $user->coins,
            'total_earned' => $totalEarned,
            'total_spent' => $totalSpent,
            'net_earned' => $totalEarned - $totalSpent,
        ];
    }

    /**
     * Get available rewards for user
     */
    public static function getAvailableRewards(User $user): array
    {
        $today = Carbon::today();

        // Check daily login reward
        $canClaimDailyLogin = self::canClaimDailyLogin($user);

        // Check registration reward
        $canClaimRegistration = !CoinTransaction::where('user_id', $user->id)
            ->where('source_type', 'reward')
            ->where('action', 'first_registration')
            ->exists();

        // Check activity reward
        $canClaimActivity = self::canClaimActivity($user);

        $activityRewardAmount = self::getActivityRewardAmount($user);

        return [
            'daily_login' => [
                'available' => $canClaimDailyLogin,
                'amount' => 5,
                'description' => 'Daily login bonus'
            ],
            'registration' => [
                'available' => $canClaimRegistration,
                'amount' => 15,
                'description' => 'First-time registration bonus'
            ],
            'activity' => [
                'available' => $canClaimActivity,
                'amount' => $activityRewardAmount,
                'description' => '30-minute activity bonus'
            ]
        ];
    }
} 