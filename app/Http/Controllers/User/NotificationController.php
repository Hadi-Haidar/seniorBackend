<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Services\NotificationService;
use App\Events\NotificationRead;
use App\Events\NotificationDeleted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class NotificationController extends Controller
{
    protected $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Get user's notifications with pagination
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        $perPage = $request->get('per_page', 15);
        $type = $request->get('type'); // Filter by type if provided

        $query = $user->notifications()
            ->notDeletedByUser()
            ->with('relatedUser:id,name,avatar')
            ->orderBy('created_at', 'desc');

        // Filter by type if specified
        if ($type && in_array($type, [
            Notification::TYPE_POST_LIKE,
            Notification::TYPE_POST_COMMENT,
            Notification::TYPE_ROOM_JOIN_REQUEST,
            Notification::TYPE_ORDER_PLACED,
            Notification::TYPE_PAYMENT_STATUS,
            Notification::TYPE_SYSTEM
        ])) {
            $query->where('type', $type);
        }

        $notifications = $query->paginate($perPage);

        // Add computed properties
        $notifications->getCollection()->transform(function ($notification) {
            $notification->icon = $notification->icon;
            $notification->color = $notification->color;
            return $notification;
        });

        return response()->json([
            'success' => true,
            'notifications' => $notifications,
            'unread_count' => $this->notificationService->getUnreadCount($user)
        ]);
    }

    /**
     * Get unread notifications count
     */
    public function unreadCount()
    {
        $user = Auth::user();
        $count = $this->notificationService->getUnreadCount($user);

        return response()->json([
            'success' => true,
            'unread_count' => $count
        ]);
    }

    /**
     * Get recent unread notifications for navbar dropdown
     */
    public function recent(Request $request)
    {
        $user = Auth::user();
        $limit = $request->get('limit', 5);

        $notifications = $user->notifications()
            ->notDeletedByUser()
            ->with('relatedUser:id,name,avatar')
            ->unread()
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        // Add computed properties
        $notifications->transform(function ($notification) {
            $notification->icon = $notification->icon;
            $notification->color = $notification->color;
            return $notification;
        });

        return response()->json([
            'success' => true,
            'notifications' => $notifications,
            'unread_count' => $this->notificationService->getUnreadCount($user)
        ]);
    }

    /**
     * Mark a specific notification as read
     */
    public function markAsRead($id)
    {
        $user = Auth::user();
        $notification = $user->notifications()->notDeletedByUser()->findOrFail($id);

        $this->notificationService->markAsRead($notification);

        $newUnreadCount = $this->notificationService->getUnreadCount($user);

        // Broadcast the read event to all connected clients
        broadcast(new NotificationRead($notification, $newUnreadCount));

        return response()->json([
            'success' => true,
            'message' => 'Notification marked as read',
            'unread_count' => $newUnreadCount
        ]);
    }

    /**
     * Mark all notifications as read
     */
    public function markAllAsRead()
    {
        $user = Auth::user();
        $updated = $this->notificationService->markAllAsRead($user);

        return response()->json([
            'success' => true,
            'message' => "{$updated} notifications marked as read",
            'unread_count' => 0
        ]);
    }

    /**
     * Mark notifications of a specific type as read
     */
    public function markTypeAsRead(Request $request)
    {
        $request->validate([
            'type' => 'required|string|in:' . implode(',', [
                Notification::TYPE_POST_LIKE,
                Notification::TYPE_POST_COMMENT,
                Notification::TYPE_ROOM_JOIN_REQUEST,
                Notification::TYPE_ORDER_PLACED,
                Notification::TYPE_PAYMENT_STATUS,
                Notification::TYPE_SYSTEM
            ])
        ]);

        $user = Auth::user();
        $updated = $this->notificationService->markTypeAsRead($user, $request->type);

        return response()->json([
            'success' => true,
            'message' => "{$updated} notifications of type {$request->type} marked as read",
            'unread_count' => $this->notificationService->getUnreadCount($user)
        ]);
    }

    /**
     * Delete a notification (soft delete - marks as deleted by user)
     */
    public function destroy($id)
    {
        $user = Auth::user();
        $notification = $user->notifications()->notDeletedByUser()->findOrFail($id);
        
        $userId = $notification->user_id;
        $notificationId = $notification->id;
        
        // Soft delete: mark as deleted by user instead of hard delete
        $notification->user_deleted_at = now();
        $notification->save();

        $newUnreadCount = $this->notificationService->getUnreadCount($user);

        // Broadcast the deleted event to all connected clients
        broadcast(new NotificationDeleted($userId, $notificationId, $newUnreadCount));

        return response()->json([
            'success' => true,
            'message' => 'Notification deleted',
            'unread_count' => $newUnreadCount
        ]);
    }

    /**
     * Get notification statistics
     */
    public function stats()
    {
        $user = Auth::user();

        $stats = [
            'total' => $user->notifications()->notDeletedByUser()->count(),
            'unread' => $user->notifications()->notDeletedByUser()->unread()->count(),
            'types' => [
                'post_like' => $user->notifications()->notDeletedByUser()->ofType(Notification::TYPE_POST_LIKE)->count(),
                'post_comment' => $user->notifications()->notDeletedByUser()->ofType(Notification::TYPE_POST_COMMENT)->count(),
                'room_join_request' => $user->notifications()->notDeletedByUser()->ofType(Notification::TYPE_ROOM_JOIN_REQUEST)->count(),
                'order_placed' => $user->notifications()->notDeletedByUser()->ofType(Notification::TYPE_ORDER_PLACED)->count(),
                'payment_status' => $user->notifications()->notDeletedByUser()->ofType(Notification::TYPE_PAYMENT_STATUS)->count(),
                'system' => $user->notifications()->notDeletedByUser()->ofType(Notification::TYPE_SYSTEM)->count(),
            ]
        ];

        return response()->json([
            'success' => true,
            'stats' => $stats
        ]);
    }

    /**
     * Get notification types for filtering
     */
    public function types()
    {
        $types = [
            [
                'value' => Notification::TYPE_POST_LIKE,
                'label' => 'Post Likes',
                'icon' => 'heart',
                'color' => 'text-red-500'
            ],
            [
                'value' => Notification::TYPE_POST_COMMENT,
                'label' => 'Post Comments',
                'icon' => 'message-circle',
                'color' => 'text-blue-500'
            ],
            [
                'value' => Notification::TYPE_ROOM_JOIN_REQUEST,
                'label' => 'Room Join Requests',
                'icon' => 'user-plus',
                'color' => 'text-green-500'
            ],
            [
                'value' => Notification::TYPE_ORDER_PLACED,
                'label' => 'Orders',
                'icon' => 'shopping-cart',
                'color' => 'text-purple-500'
            ],
            [
                'value' => Notification::TYPE_PAYMENT_STATUS,
                'label' => 'Payments',
                'icon' => 'credit-card',
                'color' => 'text-yellow-500'
            ],
            [
                'value' => Notification::TYPE_SYSTEM,
                'label' => 'System',
                'icon' => 'bell',
                'color' => 'text-blue-600'
            ]
        ];

        return response()->json([
            'success' => true,
            'types' => $types
        ]);
    }
}
