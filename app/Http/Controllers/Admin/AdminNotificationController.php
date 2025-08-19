<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\User;
use App\Models\ActivityLog;
use App\Events\NotificationCreated;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class AdminNotificationController extends Controller
{
    /**
     * Get all admin notifications with statistics (grouped by campaign)
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $type = $request->get('type');
            $status = $request->get('status');
            $targetAudience = $request->get('target_audience');

            // Get individual notifications (not grouped) and campaign notifications (grouped)
            $allNotifications = collect();

            // 1. Get individual notifications (not grouped) with user information
            $individualNotifications = Notification::select([
                'id',
                'title',
                'message',
                'admin_notification_type',
                'created_at',
                'data',
                'is_read',
                'user_id'
            ])
            ->with('user:id,name,email')
            ->where('type', Notification::TYPE_SYSTEM)
            ->whereJsonContains('data->is_individual', true)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($notification) {
                return (object) [
                    'id' => $notification->id,
                    'title' => $notification->title,
                    'message' => $notification->message,
                    'admin_notification_type' => $notification->admin_notification_type,
                    'created_at' => $notification->created_at,
                    'recipient_count' => 1,
                    'read_count' => $notification->is_read ? 1 : 0,
                    'data' => $notification->data,
                    'is_individual' => true,
                    'recipient_email' => $notification->user ? $notification->user->email : null,
                    'recipient_name' => $notification->user ? $notification->user->name : null
                ];
            });

            // 2. Get grouped campaign notifications (excluding individual notifications)
            // Get all system notifications that are NOT marked as individual
            $campaignNotificationsRaw = Notification::where('type', Notification::TYPE_SYSTEM)
                ->get()
                ->filter(function ($notification) {
                    // Check if this is NOT an individual notification
                    $data = $notification->data;
                    if (!$data) return true; // If no data, treat as campaign
                    if (!isset($data['is_individual'])) return true; // If is_individual not set, treat as campaign
                    return $data['is_individual'] !== true; // Include if is_individual is false or any other value
                });
            
            // Group by title, message, and admin_notification_type
            $campaignGroups = $campaignNotificationsRaw->groupBy(function ($notification) {
                return $notification->title . '|' . $notification->message . '|' . $notification->admin_notification_type;
            });
            
            $campaignNotifications = $campaignGroups->map(function ($group) {
                $first = $group->first();
                return (object) [
                    'id' => $first->id,
                    'title' => $first->title,
                    'message' => $first->message,
                    'admin_notification_type' => $first->admin_notification_type,
                    'created_at' => $first->created_at,
                    'recipient_count' => $group->count(),
                    'read_count' => $group->where('is_read', true)->count(),
                    'data' => $first->data,
                    'is_individual' => false
                ];
            })->values();

            // 3. Combine and sort all notifications
            // Convert to plain collections to avoid merge issues between Eloquent models and stdClass objects
            $allNotifications = collect()
                ->concat($individualNotifications->toArray())
                ->concat($campaignNotifications->toArray())
                ->sortByDesc('created_at')
                ->values();
            
            // Paginate manually
            $currentPage = $request->get('page', 1);
            $total = $allNotifications->count();
            $offset = ($currentPage - 1) * $perPage;
            $items = $allNotifications->slice($offset, $perPage)->values();
            
            // Format the paginated response
            $notifications = new \Illuminate\Pagination\LengthAwarePaginator(
                $items,
                $total,
                $perPage,
                $currentPage,
                ['path' => $request->url(), 'query' => $request->query()]
            );

            // Get statistics (count unique campaigns, not individual notifications)
            $totalCampaigns = Notification::select(['title', 'message', 'admin_notification_type'])
                ->where('type', Notification::TYPE_SYSTEM)
                ->groupBy(['title', 'message', 'admin_notification_type'])
                ->get()
                ->count();

            $stats = [
                'total' => $totalCampaigns,
                'sent' => $totalCampaigns, // Count number of campaigns/broadcasts sent by admin
                'scheduled' => 0, // No scheduling functionality
                // IMPORTANT: Count ALL notifications including user-deleted ones for accurate campaign statistics
                'total_reads' => Notification::where('type', Notification::TYPE_SYSTEM)
                    ->where('is_read', true)->count(),
                'total_recipients' => Notification::where('type', Notification::TYPE_SYSTEM)->count()
            ];

            return response()->json([
                'success' => true,
                'notifications' => $notifications,
                'stats' => $stats
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching admin notifications: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch notifications'
            ], 500);
        }
    }

    /**
     * Send notification to a specific user
     */
    public function sendToUser(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'user_id' => 'required|exists:users,id',
                'title' => 'required|string|max:255',
                'message' => 'required|string|max:1000',
                'type' => 'required|string|in:System,Feature,Policy,Promotion,Warning'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Get the target user
            $user = User::findOrFail($request->user_id);

            // Don't allow sending to admin users
            if ($user->user_type === 'admin') {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot send notifications to admin users'
                ], 400);
            }

            // Create the notification with user email in data for individual notifications
            $notification = Notification::create([
                'user_id' => $user->id,
                'type' => Notification::TYPE_SYSTEM,
                'title' => $request->title,
                'message' => $request->message,
                'data' => [
                    'admin_notification_type' => $request->type,
                    'sent_by' => 'admin',
                    'sent_at' => now()->toISOString(),
                    'recipient_email' => $user->email,
                    'recipient_name' => $user->name,
                    'is_individual' => true
                ],
                'action_url' => null,
                'related_user_id' => null,
                'admin_notification_type' => $request->type
            ]);

            // Broadcast immediately
            broadcast(new NotificationCreated($notification))->toOthers();

            // Log activity
            ActivityLog::logActivity(
                auth()->id(),
                'Sent Individual Notification',
                "User: {$user->email}",
                "Sent {$request->type} notification to {$user->name} ({$user->email})",
                'Notification Management',
                'Low',
                request()->ip()
            );

            return response()->json([
                'success' => true,
                'message' => "Notification sent successfully to {$user->name} ({$user->email})",
                'notification' => [
                    'id' => $notification->id,
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error sending notification to user: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to send notification: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create and send notification to users
     */
    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'title' => 'required|string|max:255',
                'message' => 'required|string|max:1000',
                'type' => 'required|string|in:System,Feature,Policy,Promotion,Warning',
                'target_audience' => 'required|string|in:All Users,Bronze Users,Silver Users,Gold Users,Room Owners,New Users'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Get target users
            $users = Notification::getUsersByTargetAudience($request->target_audience);

            // Exclude the admin user who is sending the notification
            $currentAdminId = auth()->id();
            $users = $users->reject(function ($user) use ($currentAdminId) {
                return $user->id === $currentAdminId;
            });

            if ($users->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No users found for the selected audience (excluding admin)'
                ], 400);
            }

            $createdNotifications = [];

            DB::transaction(function () use ($users, $request, &$createdNotifications) {
                foreach ($users as $user) {
                    $notification = Notification::createSystemNotification(
                        $user->id,
                        $request->title,
                        $request->message,
                        $request->type,
                        $request->target_audience
                    );
                    $createdNotifications[] = $notification;

                    // Always broadcast immediately
                    broadcast(new NotificationCreated($notification))->toOthers();
                }
            });

            // Log activity
            ActivityLog::logActivity(
                auth()->id(),
                'Created Notification',
                "Notification: {$request->title}",
                "Created {$request->type} notification for {$request->target_audience} ({$users->count()} recipients)",
                'Notification Management',
                'Medium',
                request()->ip()
            );

            return response()->json([
                'success' => true,
                'message' => "Notification sent successfully to " . count($createdNotifications) . " users (excluding admin)",
                'notification_count' => count($createdNotifications),
                'target_audience' => $request->target_audience
            ]);

        } catch (\Exception $e) {
            Log::error('Error creating admin notification: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to create notification: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get notification campaign details
     */
    public function show($id)
    {
        try {
            $notification = Notification::where('type', Notification::TYPE_SYSTEM)
                ->findOrFail($id);

            // Get campaign statistics for this notification group
            $campaignStats = Notification::where('type', Notification::TYPE_SYSTEM)
                ->where('title', $notification->title)
                ->where('message', $notification->message)
                ->where('admin_notification_type', $notification->admin_notification_type)
                ->selectRaw('
                    COUNT(*) as total_recipients,
                    SUM(CASE WHEN is_read = 1 THEN 1 ELSE 0 END) as read_count,
                    SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) as unread_count,
                    MIN(created_at) as campaign_created_at,
                    MAX(updated_at) as last_activity
                ')
                ->first();

            // Get sample of recipients
            $recipients = Notification::where('type', Notification::TYPE_SYSTEM)
                ->where('title', $notification->title)
                ->where('message', $notification->message)
                ->where('admin_notification_type', $notification->admin_notification_type)
                ->with('user:id,name,email')
                ->limit(10)
                ->get();

            $campaignData = [
                'id' => $notification->id,
                'title' => $notification->title,
                'message' => $notification->message,
                'admin_notification_type' => $notification->admin_notification_type,
                'created_at' => $campaignStats->campaign_created_at,
                'recipient_count' => $campaignStats->total_recipients,
                'read_count' => $campaignStats->read_count,
                'unread_count' => $campaignStats->unread_count,
                'read_percentage' => $campaignStats->total_recipients > 0 
                    ? round(($campaignStats->read_count / $campaignStats->total_recipients) * 100, 2) 
                    : 0,
                'last_activity' => $campaignStats->last_activity,
                'sample_recipients' => $recipients->map(function($n) {
                    return [
                        'user' => $n->user,
                        'is_read' => $n->is_read,
                        'read_at' => $n->read_at
                    ];
                })
            ];

            return response()->json([
                'success' => true,
                'campaign' => $campaignData
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Notification campaign not found'
            ], 404);
        }
    }

    /**
     * Delete notification campaign (all notifications with same content)
     */
    public function destroy($id)
    {
        try {
            $notification = Notification::where('type', Notification::TYPE_SYSTEM)->find($id);
            
            if (!$notification) {
                return response()->json([
                    'success' => false,
                    'message' => 'Notification not found'
                ], 404);
            }
            
            // Find and delete all related notifications with same content (entire campaign)
            $deletedCount = Notification::where('type', Notification::TYPE_SYSTEM)
                ->where('title', $notification->title)
                ->where('message', $notification->message)
                ->where('admin_notification_type', $notification->admin_notification_type)
                ->delete();

            // Log activity
            ActivityLog::logActivity(
                auth()->id(),
                'Deleted Notification',
                "Notification: {$notification->title}",
                "Deleted {$notification->admin_notification_type} notification campaign affecting {$deletedCount} recipients",
                'Notification Management',
                'High',
                request()->ip()
            );

            return response()->json([
                'success' => true,
                'message' => "Deleted notification campaign successfully ({$deletedCount} recipients affected)"
            ]);

        } catch (\Exception $e) {
            Log::error('Error deleting notification: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete notification campaign'
            ], 500);
        }
    }

    /**
     * Get notification types for dropdown
     */
    public function getTypes()
    {
        return response()->json([
            'success' => true,
            'types' => [
                'System',
                'Feature', 
                'Policy',
                'Promotion',
                'Warning'
            ]
        ]);
    }

    /**
     * Get target audiences for dropdown
     */
    public function getTargetAudiences()
    {
        $currentAdminId = auth()->id();
        
        return response()->json([
            'success' => true,
            'audiences' => [
                [
                    'value' => 'All Users',
                    'label' => 'All Users',
                    'count' => User::where('id', '!=', $currentAdminId)->count()
                ],
                [
                    'value' => 'Bronze Users',
                    'label' => 'Bronze Users',
                    'count' => User::where('subscription_level', 'bronze')
                        ->where('id', '!=', $currentAdminId)->count()
                ],
                [
                    'value' => 'Silver Users', 
                    'label' => 'Silver Users',
                    'count' => User::where('subscription_level', 'silver')
                        ->where('id', '!=', $currentAdminId)->count()
                ],
                [
                    'value' => 'Gold Users',
                    'label' => 'Gold Users', 
                    'count' => User::where('subscription_level', 'gold')
                        ->where('id', '!=', $currentAdminId)->count()
                ],
                [
                    'value' => 'Room Owners',
                    'label' => 'Room Owners',
                    'count' => User::whereHas('ownedRooms')
                        ->where('id', '!=', $currentAdminId)->count()
                ],
                [
                    'value' => 'New Users',
                    'label' => 'New Users (Last 7 days)',
                    'count' => User::where('created_at', '>=', now()->subDays(7))
                        ->where('id', '!=', $currentAdminId)->count()
                ]
            ]
        ]);
    }

    /**
     * Get notification statistics (based on campaigns, not individual notifications)
     */
    public function getStats()
    {
        try {
            // Count unique campaigns/broadcasts
            $totalCampaigns = Notification::select(['title', 'message', 'admin_notification_type'])
                ->where('type', Notification::TYPE_SYSTEM)
                ->groupBy(['title', 'message', 'admin_notification_type'])
                ->get()
                ->count();

            $stats = [
                'total' => $totalCampaigns,
                'sent' => $totalCampaigns, // Count number of campaigns/broadcasts sent by admin
                'scheduled' => 0, // No scheduling functionality
                'total_reads' => Notification::where('type', Notification::TYPE_SYSTEM)
                    ->where('is_read', true)->count(),
                'total_recipients' => Notification::where('type', Notification::TYPE_SYSTEM)->count(),
                'by_type' => Notification::select(['admin_notification_type', \DB::raw('COUNT(DISTINCT CONCAT(title, message)) as count')])
                    ->where('type', Notification::TYPE_SYSTEM)
                    ->groupBy('admin_notification_type')
                    ->pluck('count', 'admin_notification_type'),
                'recent_activity' => Notification::select(['title', 'message', 'admin_notification_type'])
                    ->where('type', Notification::TYPE_SYSTEM)
                    ->where('created_at', '>=', now()->subDays(7))
                    ->groupBy(['title', 'message', 'admin_notification_type'])
                    ->get()
                    ->count()
            ];

            return response()->json([
                'success' => true,
                'stats' => $stats
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching notification stats: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch statistics'
            ], 500);
        }
    }


}
