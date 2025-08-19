<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\CoinTransaction;
use App\Models\User;
use App\Models\UserActivity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class CoinController extends Controller
{
    /**
     * Get user's current coin balance and recent transactions
     */
    public function getBalance(Request $request)
    {
        try {
            $user = Auth::user();
            
            // Get recent transactions (last 10)
            $recentTransactions = CoinTransaction::where('user_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get()
                ->map(function ($transaction) {
                    return [
                        'id' => $transaction->id,
                        'direction' => $transaction->direction,
                        'amount' => $transaction->amount,
                        'source_type' => $transaction->source_type,
                        'action' => $transaction->action,
                        'notes' => $transaction->notes,
                        'created_at' => $transaction->created_at->format('Y-m-d H:i:s'),
                        'formatted_date' => $transaction->created_at->diffForHumans(),
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => [
                    'current_balance' => $user->coins,
                    'total_earned' => CoinTransaction::where('user_id', $user->id)
                        ->where('direction', 'in')
                        ->sum('amount'),
                    'total_spent' => CoinTransaction::where('user_id', $user->id)
                        ->where('direction', 'out')
                        ->sum('amount'),
                    'recent_transactions' => $recentTransactions,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get coin balance: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get full transaction history with pagination
     */
    public function getTransactionHistory(Request $request)
    {
        try {
            $user = Auth::user();
            $perPage = $request->get('per_page', 20);
            $page = $request->get('page', 1);

            $transactions = CoinTransaction::where('user_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->paginate($perPage, ['*'], 'page', $page);

            $transactions->getCollection()->transform(function ($transaction) {
                return [
                    'id' => $transaction->id,
                    'direction' => $transaction->direction,
                    'amount' => $transaction->amount,
                    'source_type' => $transaction->source_type,
                    'action' => $transaction->action,
                    'notes' => $transaction->notes,
                    'created_at' => $transaction->created_at->format('Y-m-d H:i:s'),
                    'formatted_date' => $transaction->created_at->diffForHumans(),
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $transactions
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get transaction history: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Purchase coins with USD (using balance)
     * 1$ = 100 coins, 2$ = 200 coins, etc.
     */
    public function purchaseCoins(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'amount_usd' => 'required|integer|min:1|max:10'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid amount. Amount must be between $1 and $10.',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $user = Auth::user();
            $amountUsd = $request->amount_usd;
            
            // Special case for $10 package (1100 coins instead of 1000)
            if ($amountUsd == 10) {
                $coinsToAdd = 1100;
            } else {
                $coinsToAdd = $amountUsd * 100; // 1$ = 100 coins
            }

            // Check if user has enough balance
            if ($user->balance < $amountUsd) {
                return response()->json([
                    'success' => false,
                    'message' => 'Insufficient balance. You need $' . $amountUsd . ' but only have $' . $user->balance
                ], 400);
            }

            DB::beginTransaction();

            // Deduct USD from balance
            $user->deductBalance($amountUsd);

            // Add coins to user
            $user->addCoins($coinsToAdd);

            // Record the transaction
            CoinTransaction::create([
                'user_id' => $user->id,
                'direction' => 'in',
                'amount' => $coinsToAdd,
                'source_type' => 'purchase',
                'action' => 'purchase_' . $coinsToAdd . '_coins',
                'notes' => "Purchased {$coinsToAdd} coins for \${$amountUsd}"
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Successfully purchased {$coinsToAdd} coins for \${$amountUsd}",
                'data' => [
                    'coins_purchased' => $coinsToAdd,
                    'amount_paid' => $amountUsd,
                    'new_coin_balance' => $user->fresh()->coins,
                    'new_usd_balance' => $user->fresh()->balance
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Purchase failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Claim daily login reward (5 coins)
     */
    public function claimDailyLoginReward(Request $request)
    {
        try {
            $user = Auth::user();
            $today = Carbon::today();

            // Check if user already claimed today's reward
            $alreadyClaimed = CoinTransaction::where('user_id', $user->id)
                ->where('source_type', 'reward')
                ->where('action', 'daily_login')
                ->whereDate('created_at', $today)
                ->exists();

            if ($alreadyClaimed) {
                return response()->json([
                    'success' => false,
                    'message' => 'Daily login reward already claimed today. Come back tomorrow!'
                ], 400);
            }

            DB::beginTransaction();

            // Add coins to user
            $rewardAmount = 5;
            $user->addCoins($rewardAmount);

            // Record the transaction
            CoinTransaction::create([
                'user_id' => $user->id,
                'direction' => 'in',
                'amount' => $rewardAmount,
                'source_type' => 'reward',
                'action' => 'daily_login',
                'notes' => 'Daily login reward'
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Daily login reward claimed! You earned {$rewardAmount} coins.",
                'data' => [
                    'coins_earned' => $rewardAmount,
                    'new_balance' => $user->fresh()->coins
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to claim daily reward: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Claim first-time registration reward (15 coins)
     */
    public function claimRegistrationReward(Request $request)
    {
        try {
            $user = Auth::user();

            // Check if user already claimed registration reward
            $alreadyClaimed = CoinTransaction::where('user_id', $user->id)
                ->where('source_type', 'reward')
                ->where('action', 'first_registration')
                ->exists();

            if ($alreadyClaimed) {
                return response()->json([
                    'success' => false,
                    'message' => 'Registration reward already claimed.'
                ], 400);
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
                'notes' => 'Welcome bonus for new user registration'
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Welcome bonus claimed! You earned {$rewardAmount} coins.",
                'data' => [
                    'coins_earned' => $rewardAmount,
                    'new_balance' => $user->fresh()->coins
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to claim registration reward: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Claim activity reward for 30 minutes of active usage
     * Fixed: 10 coins for all subscription levels
     */
    public function claimActivityReward(Request $request)
    {
        try {
            $user = Auth::user();
            $today = Carbon::today();

            // Check if user already claimed today's activity reward
            $alreadyClaimed = CoinTransaction::where('user_id', $user->id)
                ->where('source_type', 'reward')
                ->where('action', 'activity_reward')
                ->whereDate('created_at', $today)
                ->exists();

            if ($alreadyClaimed) {
                return response()->json([
                    'success' => false,
                    'message' => 'Activity reward already claimed today. Come back tomorrow!'
                ], 400);
            }

            // Check if user has completed 30 minutes of activity today
            if (!UserActivity::hasCompletedDailyActivity($user->id)) {
                $currentMinutes = UserActivity::getTodaysMinutes($user->id);
                $remainingMinutes = 30 - $currentMinutes;
                
                return response()->json([
                    'success' => false,
                    'message' => "You need to complete 30 minutes of activity to claim this reward. You have completed {$currentMinutes} minutes. {$remainingMinutes} minutes remaining.",
                    'data' => [
                        'current_minutes' => $currentMinutes,
                        'required_minutes' => 30,
                        'remaining_minutes' => $remainingMinutes
                    ]
                ], 400);
            }

            DB::beginTransaction();

            // Determine reward amount based on subscription level
            $subscriptionLevel = $user->getEffectiveSubscriptionLevel();
            $rewardAmount = 10; // Fixed amount for all subscription levels

            // Add coins to user
            $user->addCoins($rewardAmount);

            // Record the transaction
            CoinTransaction::create([
                'user_id' => $user->id,
                'direction' => 'in',
                'amount' => $rewardAmount,
                'source_type' => 'reward',
                'action' => 'activity_reward',
                'notes' => "30-minute activity reward ({$subscriptionLevel} tier)"
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Activity reward claimed! You earned {$rewardAmount} coins for 30 minutes of activity.",
                'data' => [
                    'coins_earned' => $rewardAmount,
                    'subscription_level' => $subscriptionLevel,
                    'activity_minutes_completed' => UserActivity::getTodaysMinutes($user->id),
                    'new_balance' => $user->fresh()->coins
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to claim activity reward: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Spend coins (deduct from balance)
     */
    public function spendCoins(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'required|integer|min:1',
            'action' => 'required|string|max:255',
            'notes' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $user = Auth::user();
            $amount = $request->amount;

            // Check if user has enough coins
            if ($user->coins < $amount) {
                return response()->json([
                    'success' => false,
                    'message' => "Insufficient coins. You need {$amount} coins but only have {$user->coins}"
                ], 400);
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
                'action' => $request->action,
                'notes' => $request->notes ?: "Spent {$amount} coins on {$request->action}"
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Successfully spent {$amount} coins",
                'data' => [
                    'coins_spent' => $amount,
                    'action' => $request->action,
                    'new_balance' => $user->fresh()->coins
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to spend coins: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Check if user can claim rewards
     */
    public function checkAvailableRewards(Request $request)
    {
        try {
            $user = Auth::user();
            $today = Carbon::today();

            // Check daily login reward
            $canClaimDailyLogin = !CoinTransaction::where('user_id', $user->id)
                ->where('source_type', 'reward')
                ->where('action', 'daily_login')
                ->whereDate('created_at', $today)
                ->exists();

            // Check registration reward
            $canClaimRegistration = !CoinTransaction::where('user_id', $user->id)
                ->where('source_type', 'reward')
                ->where('action', 'first_registration')
                ->exists();

            // Check activity reward
            $alreadyClaimedActivity = CoinTransaction::where('user_id', $user->id)
                ->where('source_type', 'reward')
                ->where('action', 'activity_reward')
                ->whereDate('created_at', $today)
                ->exists();

            $hasCompletedActivity = UserActivity::hasCompletedDailyActivity($user->id);
            $canClaimActivity = !$alreadyClaimedActivity && $hasCompletedActivity;

            $subscriptionLevel = $user->getEffectiveSubscriptionLevel();
            $activityRewardAmount = 10; // Fixed amount for all subscription levels

            // Get current activity progress
            $currentActivityMinutes = UserActivity::getTodaysMinutes($user->id);
            $remainingActivityMinutes = max(0, 30 - $currentActivityMinutes);

            return response()->json([
                'success' => true,
                'data' => [
                    'current_balance' => $user->coins,
                    'subscription_level' => $subscriptionLevel,
                    'activity_progress' => [
                        'current_minutes' => $currentActivityMinutes,
                        'required_minutes' => 30,
                        'remaining_minutes' => $remainingActivityMinutes,
                        'completed' => $hasCompletedActivity
                    ],
                    'available_rewards' => [
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
                            'description' => '30-minute activity bonus',
                            'requirements_met' => $hasCompletedActivity,
                            'already_claimed' => $alreadyClaimedActivity
                        ]
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to check available rewards: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get coin purchase options
     */
    public function getPurchaseOptions(Request $request)
    {
        $user = Auth::user();
        
        return response()->json([
            'success' => true,
            'data' => [
                'current_balance_usd' => $user->balance,
                'current_balance_coins' => $user->coins,
                'purchase_options' => [
                    ['amount_usd' => 1, 'coins' => 100],
                    ['amount_usd' => 2, 'coins' => 200],
                    ['amount_usd' => 3, 'coins' => 300],
                    ['amount_usd' => 4, 'coins' => 400],
                    ['amount_usd' => 5, 'coins' => 500],
                    ['amount_usd' => 10, 'coins' => 1100],
                ],
                'exchange_rate' => '1 USD = 100 Coins'
            ]
        ]);
    }

    /**
     * Record user activity (call this endpoint periodically to track user activity)
     */
    public function recordActivity(Request $request)
    {
        try {
            $user = Auth::user();
            $minutesToAdd = $request->input('minutes', 1); // Default to 1 minute
            
            // Validate minutes input
            if ($minutesToAdd < 1 || $minutesToAdd > 60) {
                return response()->json([
                    'success' => false,
                    'message' => 'Minutes must be between 1 and 60'
                ], 400);
            }

            UserActivity::updateActivity($user->id, $minutesToAdd);
            $currentMinutes = UserActivity::getTodaysMinutes($user->id);
            $hasCompleted30Minutes = $currentMinutes >= 30;

            return response()->json([
                'success' => true,
                'message' => 'Activity recorded successfully',
                'data' => [
                    'total_minutes_today' => $currentMinutes,
                    'minutes_added' => $minutesToAdd,
                    'can_claim_reward' => $hasCompleted30Minutes && !CoinTransaction::where('user_id', $user->id)
                        ->where('source_type', 'reward')
                        ->where('action', 'activity_reward')
                        ->whereDate('created_at', Carbon::today())
                        ->exists()
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to record activity: ' . $e->getMessage()
            ], 500);
        }
    }
}
