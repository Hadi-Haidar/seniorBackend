<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SubscriptionController extends Controller
{
    /**
     * Subscription price constants (in USD)
     */
    const SUBSCRIPTION_PRICES = [
        'silver' => 6.00,
        'gold' => 10.00
    ];

    /**
     * Upgrade user subscription
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function upgrade(Request $request)
    {
        // Validate request
        $request->validate([
            'level' => 'required|string|in:silver,gold',
        ]);

        $user = Auth::user();
        $targetLevel = $request->level;
        
        // Check if user has an active subscription
        $currentSubscription = $user->currentSubscription();
        
        // Prevent downgrading from gold to silver
        if ($currentSubscription && $currentSubscription->level === 'gold' && $targetLevel === 'silver') {
            return response()->json([
                'success' => false,
                'message' => "You cannot downgrade from Gold to Silver subscription."
            ], 400);
        }
        
        // Check if user already has the same level
        if ($currentSubscription && $currentSubscription->level === $targetLevel) {
            return response()->json([
                'success' => false,
                'message' => "You already have an active {$targetLevel} subscription."
            ], 400);
        }

        // Also check user's subscription_level in users table to prevent downgrade
        if ($user->subscription_level === 'gold' && $targetLevel === 'silver') {
            return response()->json([
                'success' => false,
                'message' => "You cannot downgrade from Gold to Silver subscription."
            ], 400);
        }

        // Get subscription price
        $price = self::SUBSCRIPTION_PRICES[$targetLevel];
        
        // Check if user has enough balance
        if ($user->balance < $price) {
            return response()->json([
                'success' => false,
                'message' => "Insufficient balance. You need $" . number_format($price, 2) . " to upgrade to {$targetLevel}.",
                'current_balance' => number_format($user->balance, 2),
                'required_amount' => number_format($price, 2)
            ], 400);
        }

        // Process upgrade using database transaction
        try {
            DB::beginTransaction();
            
            // Deduct balance first
            if (!$user->deductBalance($price)) {
                throw new \Exception('Failed to deduct balance');
            }
            
            // Double check balance was actually deducted
            $user->refresh();
            if ($user->balance < 0) {
                throw new \Exception('Balance went negative');
            }
            
            // Deactivate current subscription if exists
            if ($currentSubscription) {
                $currentSubscription->deactivate();
            }
            
            // Create new subscription
            $startDate = now();
            $endDate = $startDate->copy()->addDays(30);
            
            $subscription = Subscription::create([
                'user_id' => $user->id,
                'level' => $targetLevel,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'is_active' => true
            ]);
            
            // Update user's subscription level
            $user->subscription_level = $targetLevel;
            $user->save();
            
            DB::commit();
            
            // Refresh user data to get the latest state
            $user->refresh();
            
            return response()->json([
                'success' => true,
                'message' => "Successfully upgraded to {$targetLevel} subscription!",
                'subscription' => [
                    'level' => $subscription->level,
                    'start_date' => $subscription->start_date->format('Y-m-d'),
                    'end_date' => $subscription->end_date->format('Y-m-d'),
                    'days_remaining' => $subscription->days_remaining,
                ],
                'previous_balance' => number_format($user->balance + $price, 2),
                'deducted_amount' => number_format($price, 2),
                'remaining_balance' => number_format($user->balance, 2),
                // Include fresh user data for immediate frontend update
                'updated_user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'avatar' => $user->avatar,
                    'bio' => $user->bio,
                    'google_id' => $user->google_id,
                    'subscription_level' => $user->subscription_level,
                    'coins' => $user->coins,
                    'balance' => $user->balance,
                    'email_verified_at' => $user->email_verified_at,
                    'created_at' => $user->created_at,
                    'updated_at' => $user->updated_at
                ]
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Upgrade failed. Please try again.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get user's current USD balance and subscription info
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getBalance()
    {
        $user = Auth::user();
        $currentSubscription = $user->currentSubscription();
        
        return response()->json([
            'success' => true,
            'balance' => number_format($user->balance, 2),
            'coins' => $user->coins,
            'subscription_level' => $user->subscription_level,
            'active_subscription' => $currentSubscription ? [
                'level' => $currentSubscription->level,
                'start_date' => $currentSubscription->start_date->format('Y-m-d'),
                'end_date' => $currentSubscription->end_date->format('Y-m-d'),
                'days_remaining' => $currentSubscription->days_remaining,
                'is_active' => $currentSubscription->is_active
            ] : null
        ]);
    }
    
    /**
     * Get user's subscription payment history
     *
     * @param User $user
     * @return \Illuminate\Database\Eloquent\Collection
     */
    private function getUserSubscriptionHistory(User $user)
    {
        return Subscription::where('user_id', $user->id)
                    ->orderBy('created_at', 'desc')
                    ->get();
    }
    
    /**
     * Get numeric value for subscription level for comparison
     *
     * @param string $level
     * @return int
     */
    private function levelValue($level)
    {
        $levels = ['bronze' => 1, 'silver' => 2, 'gold' => 3];
        return $levels[$level] ?? 0;
    }

    /**
     * List all subscription payments for the authenticated user
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function transactions()
    {
        $user = Auth::user();
        $subscriptions = Subscription::where('user_id', $user->id)
                        ->orderBy('created_at', 'desc')
                        ->get()
                        ->map(function ($subscription) {
                            return [
                                'id' => $subscription->id,
                                'level' => $subscription->level,
                                'start_date' => $subscription->start_date->format('Y-m-d'),
                                'end_date' => $subscription->end_date->format('Y-m-d'),
                                'is_active' => $subscription->is_active,
                                'days_remaining' => $subscription->days_remaining,
                                'created_at' => $subscription->created_at->format('Y-m-d H:i:s')
                            ];
                        });
        
        return response()->json([
            'success' => true,
            'subscriptions' => $subscriptions,
            'total_subscriptions' => $subscriptions->count()
        ]);
    }

    /**
     * Get subscription pricing
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function getPricing()
    {
        return response()->json([
            'success' => true,
            'pricing' => [
                'bronze' => ['price' => 0, 'features' => ['Basic features']],
                'silver' => ['price' => self::SUBSCRIPTION_PRICES['silver'], 'features' => ['All bronze features', 'Additional silver features']],
                'gold' => ['price' => self::SUBSCRIPTION_PRICES['gold'], 'features' => ['All silver features', 'Premium gold features']]
            ]
        ]);
    }
}
