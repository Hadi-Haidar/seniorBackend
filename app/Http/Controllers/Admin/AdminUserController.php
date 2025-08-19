<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AdminUserController extends Controller
{
    /**
     * Get all users with filtering, sorting, and pagination
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        try {
            $query = User::withCount(['posts', 'ownedRooms', 'coinTransactions']);

            // Search by name or email
            if ($request->filled('search')) {
                $search = $request->get('search');
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");
                });
            }

            // Filter by subscription level
            if ($request->filled('subscription_level') && $request->subscription_level !== 'all') {
                $query->where('subscription_level', $request->subscription_level);
            }

            // Filter by status
            if ($request->filled('status') && $request->status !== 'all') {
                $query->where('status', $request->status);
            }

            // Filter by user type
            if ($request->filled('user_type') && $request->user_type !== 'all') {
                $query->where('user_type', $request->user_type);
            }

            // Sort by
            $sortBy = $request->get('sort_by', 'created_at');
            $sortOrder = $request->get('sort_order', 'desc');
            
            // Validate sort fields
            $allowedSortFields = ['created_at', 'name', 'email', 'subscription_level', 'status', 'coins', 'balance'];
            if (in_array($sortBy, $allowedSortFields)) {
                $query->orderBy($sortBy, $sortOrder);
            }

            // Pagination
            $perPage = $request->get('per_page', 15);
            $users = $query->paginate($perPage);

            // Transform the data to include additional statistics
            $users->getCollection()->transform(function ($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'avatar' => $user->avatar,
                    'subscription_level' => $user->subscription_level,
                    'user_type' => $user->user_type,
                    'status' => $user->status,
                    'coins' => $user->coins,
                    'balance' => $user->balance,
                    'email_verified_at' => $user->email_verified_at,
                    'google_id' => $user->google_id ? true : false, // Just boolean for privacy
                    'created_at' => $user->created_at,
                    'updated_at' => $user->updated_at,
                    // Frontend expects these fields directly on the user object
                    'posts_count' => $user->posts_count,
                    'rooms_count' => $user->owned_rooms_count,
                    'transactions_count' => $user->coin_transactions_count,
                    'days_since_joined' => $user->created_at->diffInDays(now()),
                ];
            });

            // Get summary statistics
            $totalUsers = User::count();
            $activeUsers = User::where('status', 'active')->count();
            $bannedUsers = User::where('status', 'banned')->count();
            $subscriptionStats = [
                'bronze' => User::where('subscription_level', 'bronze')->count(),
                'silver' => User::where('subscription_level', 'silver')->count(),
                'gold' => User::where('subscription_level', 'gold')->count(),
            ];

            return response()->json([
                'success' => true,
                'data' => $users,
                'summary' => [
                    'total_users' => $totalUsers,
                    'active_users' => $activeUsers,
                    'banned_users' => $bannedUsers,
                    'subscription_stats' => $subscriptionStats,
                    'showing' => $users->count(),
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch users: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a specific user by ID
     * 
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(int $id)
    {
        try {
            $user = User::withCount(['posts', 'ownedRooms', 'coinTransactions', 'orders'])
                ->with([
                    'coinTransactions' => function ($query) {
                        $query->latest()->limit(10);
                    },
                    'posts' => function ($query) {
                        $query->latest()->limit(5);
                    },
                    'ownedRooms',
                    'orders' => function ($query) {
                        $query->latest()->limit(5);
                    }
                ])->find($id);

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }

            // Add count fields directly to the user object for frontend compatibility
            $userData = $user->toArray();
            $userData['posts_count'] = $user->posts_count;
            $userData['rooms_count'] = $user->owned_rooms_count;
            $userData['transactions_count'] = $user->coin_transactions_count;
            $userData['orders_count'] = $user->orders_count;

            return response()->json([
                'success' => true,
                'data' => [
                    'user' => $userData,
                    'detailed_stats' => [
                        'total_spent_coins' => $user->coinTransactions()
                            ->where('direction', 'out')
                            ->sum('amount'),
                        'total_earned_coins' => $user->coinTransactions()
                            ->where('direction', 'in')
                            ->sum('amount'),
                        'total_orders' => $user->orders_count,
                        'account_age_days' => $user->created_at->diffInDays(now()),
                        'last_login' => $user->updated_at, // Approximate
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch user: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update user subscription level
     * 
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateSubscription(Request $request, int $id)
    {
        $validator = Validator::make($request->all(), [
            'subscription_level' => 'required|in:bronze,silver,gold'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $user = User::find($id);
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }

            // Prevent admin from downgrading themselves if they're the only admin
            if ($user->user_type === 'admin' && $request->subscription_level === 'bronze') {
                $adminCount = User::where('user_type', 'admin')->count();
                if ($adminCount <= 1) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Cannot downgrade the only admin user'
                    ], 400);
                }
            }

            $oldLevel = $user->subscription_level;
            $user->subscription_level = $request->subscription_level;
            $user->save();

            // Log the change (you can expand this to create an audit log)
            \Log::info("Admin updated user subscription", [
                'admin_id' => auth()->id(),
                'user_id' => $user->id,
                'old_level' => $oldLevel,
                'new_level' => $request->subscription_level
            ]);

            // Log activity to activity logs
            ActivityLog::logActivity(
                auth()->id(),
                'Updated User Subscription',
                "User: {$user->email}",
                "Subscription level changed from {$oldLevel} to {$request->subscription_level}",
                'User Management',
                'Medium',
                $request->ip()
            );

            return response()->json([
                'success' => true,
                'message' => "User subscription updated from {$oldLevel} to {$request->subscription_level}",
                'data' => [
                    'user_id' => $user->id,
                    'old_subscription' => $oldLevel,
                    'new_subscription' => $user->subscription_level
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update subscription: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update user status (active/banned)
     * 
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateStatus(Request $request, int $id)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:active,banned',
            'reason' => 'nullable|string|max:500' // Optional reason for status change
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $user = User::find($id);
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }

            // Prevent admin from banning themselves
            if ($user->id === auth()->id() && $request->status === 'banned') {
                return response()->json([
                    'success' => false,
                    'message' => 'You cannot ban yourself'
                ], 400);
            }

            // Prevent banning the only admin
            if ($user->user_type === 'admin' && $request->status === 'banned') {
                $adminCount = User::where('user_type', 'admin')->where('status', 'active')->count();
                if ($adminCount <= 1) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Cannot ban the only active admin user'
                    ], 400);
                }
            }

            $oldStatus = $user->status;
            $user->status = $request->status;
            $user->save();

            // If banning user, revoke all their API tokens
            if ($request->status === 'banned') {
                $user->tokens()->delete();
            }

            // Log the change
            \Log::info("Admin updated user status", [
                'admin_id' => auth()->id(),
                'user_id' => $user->id,
                'old_status' => $oldStatus,
                'new_status' => $request->status,
                'reason' => $request->reason
            ]);

            // Log activity to activity logs
            $action = $request->status === 'banned' ? 'Banned User' : 'Unbanned User';
            $severity = $request->status === 'banned' ? 'High' : 'Medium';
            $details = $request->reason ? 
                "User status changed from {$oldStatus} to {$request->status}. Reason: {$request->reason}" :
                "User status changed from {$oldStatus} to {$request->status}";

            ActivityLog::logActivity(
                auth()->id(),
                $action,
                "User: {$user->email}",
                $details,
                'User Management',
                $severity,
                $request->ip()
            );

            return response()->json([
                'success' => true,
                'message' => "User status updated from {$oldStatus} to {$request->status}",
                'data' => [
                    'user_id' => $user->id,
                    'old_status' => $oldStatus,
                    'new_status' => $user->status
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update status: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a user (soft delete)
     * 
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy(Request $request, int $id)
    {
        $validator = Validator::make($request->all(), [
            'reason' => 'nullable|string|max:500'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $user = User::find($id);
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }

            // Prevent admin from deleting themselves
            if ($user->id === auth()->id()) {
                return response()->json([
                    'success' => false,
                    'message' => 'You cannot delete yourself'
                ], 400);
            }

            // Prevent deleting the only admin
            if ($user->user_type === 'admin') {
                $adminCount = User::where('user_type', 'admin')->count();
                if ($adminCount <= 1) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Cannot delete the only admin user'
                    ], 400);
                }
            }

            // Revoke all API tokens before deletion
            $user->tokens()->delete();

            // Soft delete the user
            $user->delete();

            // Log the deletion
            \Log::warning("Admin deleted user account", [
                'admin_id' => auth()->id(),
                'deleted_user_id' => $user->id,
                'deleted_user_email' => $user->email,
                'reason' => $request->reason
            ]);

            // Log activity to activity logs
            $details = $request->reason ? 
                "User account deleted. Reason: {$request->reason}" :
                "User account deleted";

            ActivityLog::logActivity(
                auth()->id(),
                'Deleted User Account',
                "User: {$user->email}",
                $details,
                'User Management',
                'Critical',
                $request->ip()
            );

            return response()->json([
                'success' => true,
                'message' => 'User account has been deleted successfully',
                'data' => [
                    'deleted_user_id' => $user->id,
                    'deleted_at' => now()
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete user: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Restore a soft-deleted user
     * 
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function restore(int $id)
    {
        try {
            $user = User::withTrashed()->find($id);
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }

            if (!$user->trashed()) {
                return response()->json([
                    'success' => false,
                    'message' => 'User is not deleted'
                ], 400);
            }

            $user->restore();

            // Log the restoration
            \Log::info("Admin restored user account", [
                'admin_id' => auth()->id(),
                'restored_user_id' => $user->id,
                'restored_user_email' => $user->email
            ]);

            // Log activity to activity logs
            ActivityLog::logActivity(
                auth()->id(),
                'Restored User Account',
                "User: {$user->email}",
                "Previously deleted user account has been restored",
                'User Management',
                'Medium',
                request()->ip()
            );

            return response()->json([
                'success' => true,
                'message' => 'User account has been restored successfully',
                'data' => [
                    'restored_user_id' => $user->id,
                    'restored_at' => now()
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to restore user: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get deleted users
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getDeleted(Request $request)
    {
        try {
            $query = User::onlyTrashed();

            // Search in deleted users
            if ($request->filled('search')) {
                $search = $request->get('search');
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");
                });
            }

            $perPage = $request->get('per_page', 15);
            $deletedUsers = $query->orderBy('deleted_at', 'desc')->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $deletedUsers
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch deleted users: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk operations on multiple users
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function bulkAction(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'action' => 'required|in:ban,activate,delete,upgrade_silver,upgrade_gold,downgrade_bronze',
            'user_ids' => 'required|array|min:1',
            'user_ids.*' => 'integer|exists:users,id',
            'reason' => 'nullable|string|max:500'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $userIds = $request->user_ids;
            $action = $request->action;
            $currentUserId = auth()->id();

            // Prevent admin from performing bulk actions on themselves
            if (in_array($currentUserId, $userIds)) {
                return response()->json([
                    'success' => false,
                    'message' => 'You cannot perform bulk actions on yourself'
                ], 400);
            }

            $results = [];
            $successCount = 0;
            $errorCount = 0;

            foreach ($userIds as $userId) {
                try {
                    $user = User::find($userId);
                    if (!$user) {
                        $results[] = ['user_id' => $userId, 'status' => 'error', 'message' => 'User not found'];
                        $errorCount++;
                        continue;
                    }

                    // Skip admin users for certain actions
                    if ($user->user_type === 'admin' && in_array($action, ['ban', 'delete'])) {
                        $results[] = ['user_id' => $userId, 'status' => 'skipped', 'message' => 'Cannot perform this action on admin users'];
                        continue;
                    }

                    switch ($action) {
                        case 'ban':
                            $user->status = 'banned';
                            $user->tokens()->delete(); // Revoke tokens
                            break;
                        case 'activate':
                            $user->status = 'active';
                            break;
                        case 'delete':
                            $user->tokens()->delete(); // Revoke tokens
                            $user->delete();
                            break;
                        case 'upgrade_silver':
                            $user->subscription_level = 'silver';
                            break;
                        case 'upgrade_gold':
                            $user->subscription_level = 'gold';
                            break;
                        case 'downgrade_bronze':
                            $user->subscription_level = 'bronze';
                            break;
                    }

                    if ($action !== 'delete') {
                        $user->save();
                    }

                    $results[] = ['user_id' => $userId, 'status' => 'success', 'message' => 'Action completed'];
                    $successCount++;

                } catch (\Exception $e) {
                    $results[] = ['user_id' => $userId, 'status' => 'error', 'message' => $e->getMessage()];
                    $errorCount++;
                }
            }

            // Log bulk action
            \Log::info("Admin performed bulk action", [
                'admin_id' => $currentUserId,
                'action' => $action,
                'user_ids' => $userIds,
                'success_count' => $successCount,
                'error_count' => $errorCount,
                'reason' => $request->reason
            ]);

            return response()->json([
                'success' => true,
                'message' => "Bulk action completed. {$successCount} successful, {$errorCount} failed.",
                'data' => [
                    'action' => $action,
                    'success_count' => $successCount,
                    'error_count' => $errorCount,
                    'results' => $results
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to perform bulk action: ' . $e->getMessage()
            ], 500);
        }
    }
} 