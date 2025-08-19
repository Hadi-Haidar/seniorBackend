<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Broadcast;
use App\Http\Controllers\Auth\{AuthController, GoogleController, PasswordResetController};
use App\Http\Controllers\User\{
    SubscriptionController, ProfileController, RoomController, PostController,
    ProductController, OrderController, PaymentController, CoinController,
    CartController, FavoriteController, StoreController, ProductReviewController,
    DashboardController, SupportController, NotificationController, PostReportController
};
use App\Http\Controllers\{
    ChatRoomController, ChatMessageController, DirectMessageController, OnlineMemberController
};
use App\Http\Controllers\Admin\{
    AdminDashboardController, AdminUserController, AdminRoomController,
    AdminPaymentController, AdminActivityLogController, AdminSupportController,
    AdminContentModerationController, AdminNotificationController
};
use App\Http\Controllers\ImageController;

// User endpoint
Route::get('/user', function (Request $request) {
    $user = $request->user();
    if ($user) {
        $user->refresh();
        $user->makeVisible(['user_type']);
    }
    return $user;
})->middleware('auth:sanctum');

// Authentication Routes (Public)
Route::prefix('auth')->group(function () {
    Route::get('google', [GoogleController::class, 'redirectToGoogle']);
    Route::get('google/callback', [GoogleController::class, 'handleGoogleCallback']);
});

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/verify-email', [AuthController::class, 'verifyEmail']);
Route::post('/resend-verification-code', [AuthController::class, 'resendVerificationCode']);

// Password Reset Routes (Public)
Route::prefix('password')->group(function () {
    Route::post('/forgot', [PasswordResetController::class, 'sendResetLinkEmail']);
    Route::post('/reset', [PasswordResetController::class, 'reset']);
});


Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLinkEmail']);
Route::post('/reset-password', [PasswordResetController::class, 'reset']);

// Optimized Image Serving Routes (Public)
Route::get('/avatars/{filename}', [ImageController::class, 'serveAvatar'])->name('avatar.optimized');
Route::get('/images/products/{filename}', [ImageController::class, 'serveProductImage'])->name('product.image.optimized');

// Protected Routes
Route::middleware('auth:sanctum')->group(function () {
    
    // Authentication
    Route::post('/logout', [AuthController::class, 'logout']);
    
    // User Profile
    Route::prefix('profile')->group(function () {
        Route::get('/', [ProfileController::class, 'getProfile']);
        Route::post('/update', [ProfileController::class, 'updateProfile']);
        Route::post('/change-password', [ProfileController::class, 'changePassword']);
        Route::post('/generate-default-avatar', [ProfileController::class, 'generateMyDefaultAvatar']);
        Route::delete('/account/delete', [ProfileController::class, 'deleteAccount']);
    });
    
    // Subscription Management
    Route::prefix('user/subscription')->group(function () {
        Route::get('/balance', [SubscriptionController::class, 'getBalance']);
        Route::post('/upgrade', [SubscriptionController::class, 'upgrade']);
        Route::get('/pricing', [SubscriptionController::class, 'getPricing']);
        Route::get('/transactions', [SubscriptionController::class, 'transactions']);
    });
    
    // Room Management
    Route::prefix('rooms')->group(function () {
        Route::get('/', [RoomController::class, 'index']);
        Route::post('/', [RoomController::class, 'store']);
        Route::get('/usage/summary', [RoomController::class, 'getRoomUsageSummary']);
        Route::get('/{id}', [RoomController::class, 'show']);
        Route::put('/{id}', [RoomController::class, 'update']);
        Route::delete('/{id}', [RoomController::class, 'destroy']);
        
        // Room Membership
        Route::post('/{id}/join', [RoomController::class, 'join']);
        Route::post('/{id}/leave', [RoomController::class, 'leave']);
        Route::get('/{roomId}/check-membership', [RoomController::class, 'checkMembership']);
        Route::post('/check-bulk-membership', [RoomController::class, 'checkBulkMembership']);
        Route::get('/{roomId}/members', [RoomController::class, 'getRoomMembers']);
        Route::get('/{roomId}/pending-requests', [RoomController::class, 'pendingRequests']);
        
        // Member Management
        Route::post('/{roomId}/members/{userId}/approve', [RoomController::class, 'approveMember']);
        Route::post('/{roomId}/members/{userId}/reject', [RoomController::class, 'rejectMember']);
        Route::post('/{roomId}/members/{userId}/remove', [RoomController::class, 'removeMember']);
        Route::post('/{roomId}/members/{userId}/promote', [RoomController::class, 'promoteToModerator']);
        Route::post('/{roomId}/members/{userId}/demote', [RoomController::class, 'demoteToMember']);
    });
    
    // Chat System
    Route::prefix('chat-rooms/{room}')->group(function () {
        // Messages
        Route::get('/messages', [ChatMessageController::class, 'index']);
        Route::post('/messages', [ChatMessageController::class, 'store']);
        Route::post('/typing', [ChatMessageController::class, 'typing']);
        Route::post('/upload', [ChatMessageController::class, 'uploadFile']);
        
        // Online Members
        Route::get('/online-members', [OnlineMemberController::class, 'getOnlineMembers']);
        Route::post('/mark-online', [OnlineMemberController::class, 'markOnline']);
        Route::post('/mark-offline', [OnlineMemberController::class, 'markOffline']);
        Route::post('/update-activity', [OnlineMemberController::class, 'updateActivity']);
    });
    
    Route::put('chat-messages/{message}', [ChatMessageController::class, 'update']);
    Route::delete('chat-messages/{message}', [ChatMessageController::class, 'destroy']);
    
    // Direct Messages
    Route::prefix('rooms/{room}/direct-messages')->group(function () {
        Route::get('conversations', [DirectMessageController::class, 'getRoomConversations']);
        Route::get('conversations/{user}', [DirectMessageController::class, 'getConversation']);
        Route::post('send/{user}', [DirectMessageController::class, 'sendMessage']);
        Route::post('conversations/{user}/read', [DirectMessageController::class, 'markAsRead']);
        Route::post('typing/{user}', [DirectMessageController::class, 'sendTyping']);
        Route::put('edit/{message}', [DirectMessageController::class, 'editMessage']);
        Route::delete('delete/{message}', [DirectMessageController::class, 'deleteMessage']);
    });
    
    // Posts Management
    Route::prefix('rooms/{roomId}/posts')->group(function () {
        Route::get('/', [PostController::class, 'index']);
        Route::post('/', [PostController::class, 'store']);
        Route::get('/{postId}', [PostController::class, 'show']);
        Route::put('/{postId}', [PostController::class, 'update']);
        Route::delete('/{postId}', [PostController::class, 'destroy']);
        
        // Post Interactions
        Route::post('/{postId}/like', [PostController::class, 'likePost']);
        Route::delete('/{postId}/like', [PostController::class, 'unlikePost']);
        Route::get('/{postId}/likes', [PostController::class, 'getPostLikes']);
        
        // Comments
        Route::get('/{postId}/member-comments', [PostController::class, 'memberComments']);
        Route::get('/{postId}/public-comments', [PostController::class, 'publicComments']);
        Route::post('/{postId}/comments', [PostController::class, 'storeComment']);
        Route::put('/{postId}/comments/{commentId}', [PostController::class, 'updateComment']);
        Route::delete('/{postId}/comments/{commentId}', [PostController::class, 'destroyComment']);
    });
    
    // Public Posts (consolidated routes)
    Route::get('/public-posts', [PostController::class, 'publicPosts']);
    Route::get('/featured-public-posts', [PostController::class, 'featuredPublicPosts']);
    Route::get('/rooms/{roomId}/public-posts', [PostController::class, 'roomPublicPosts']);
    
    // Product Management
    Route::prefix('rooms/{roomId}/products')->group(function () {
        Route::get('/', [ProductController::class, 'index']);
        Route::post('/', [ProductController::class, 'store']);
    });
    
    Route::prefix('products/{productId}')->group(function () {
        Route::get('/', [ProductController::class, 'show']);
        Route::put('/', [ProductController::class, 'update']);
        Route::delete('/', [ProductController::class, 'destroy']);
        Route::put('/visibility', [StoreController::class, 'toggleVisibility']);
        
        // Product Reviews
        Route::get('/reviews', [ProductReviewController::class, 'index']);
        Route::get('/reviews/can-review', [ProductReviewController::class, 'canReview']);
        Route::get('/reviews/user', [ProductReviewController::class, 'getUserReview']);
        Route::post('/reviews', [ProductReviewController::class, 'store']);
        Route::put('/reviews/{reviewId}', [ProductReviewController::class, 'update']);
        Route::delete('/reviews/{reviewId}', [ProductReviewController::class, 'destroy']);
    });
    
    // Store
    Route::prefix('store')->group(function () {
        Route::get('/products', [StoreController::class, 'getPublicProducts']);
        Route::get('/categories', [StoreController::class, 'getCategories']);
        Route::get('/rooms', [StoreController::class, 'getRoomsWithPublicProducts']);
    });
    
    // Shopping Cart
    Route::prefix('cart')->group(function () {
        Route::get('/', [CartController::class, 'index']);
        Route::post('/', [CartController::class, 'store']);
        Route::put('/{cartItemId}', [CartController::class, 'update']);
        Route::delete('/{cartItemId}', [CartController::class, 'destroy']);
        Route::delete('/', [CartController::class, 'clear']);
        Route::get('/count', [CartController::class, 'count']);
        Route::post('/purchase-all', [CartController::class, 'purchaseAll']);
    });
    
    // Favorites
    Route::prefix('favorites')->group(function () {
        Route::get('/', [FavoriteController::class, 'index']);
        Route::post('/toggle', [FavoriteController::class, 'toggle']);
        Route::delete('/{favoriteId}', [FavoriteController::class, 'destroy']);
        Route::get('/check/{productId}', [FavoriteController::class, 'check']);
    });
    
    // Order Management
    Route::prefix('orders')->group(function () {
        Route::get('/', [OrderController::class, 'index']);
        Route::post('/', [OrderController::class, 'store']);
        Route::get('/{id}', [OrderController::class, 'show']);
        Route::put('/{id}/status', [OrderController::class, 'updateStatus']);
        Route::put('/{id}/cancel', [OrderController::class, 'cancel']);
        
        // Order Messaging
        Route::post('/{orderId}/messages', [OrderController::class, 'sendMessage']);
        Route::get('/{orderId}/messages', [OrderController::class, 'getMessages']);
        Route::delete('/{orderId}/chat', [OrderController::class, 'deleteChat']);
    });
    
    // Payment System
    Route::prefix('user/payment')->group(function () {
        Route::post('/create', [PaymentController::class, 'createPayment']);
        Route::post('/cancel', [PaymentController::class, 'cancelPayment']);
        Route::get('/history', [PaymentController::class, 'getPayments']);
        Route::get('/qr-code', [AdminPaymentController::class, 'getActiveQrCode']);
    });
    
    // Coin System
    Route::prefix('user/coins')->group(function () {
        Route::get('/balance', [CoinController::class, 'getBalance']);
        Route::get('/history', [CoinController::class, 'getTransactionHistory']);
        Route::get('/purchase-options', [CoinController::class, 'getPurchaseOptions']);
        Route::post('/purchase', [CoinController::class, 'purchaseCoins']);
        Route::post('/spend', [CoinController::class, 'spendCoins']);
        Route::get('/rewards/available', [CoinController::class, 'checkAvailableRewards']);
        Route::post('/rewards/daily-login', [CoinController::class, 'claimDailyLoginReward']);
        Route::post('/rewards/registration', [CoinController::class, 'claimRegistrationReward']);
        Route::post('/rewards/activity', [CoinController::class, 'claimActivityReward']);
        Route::post('/activity/record', [CoinController::class, 'recordActivity']);
    });
    
    // User Dashboard
    Route::prefix('user')->group(function () {
        Route::post('/activity/update', [DashboardController::class, 'updateActivity']);
        Route::get('/dashboard', [DashboardController::class, 'getDashboardData']);
    });
    
    // Support System
    Route::prefix('support')->group(function () {
        Route::get('/', [SupportController::class, 'index']);
        Route::post('/', [SupportController::class, 'store']);
        Route::get('/stats', [SupportController::class, 'getStats']);
        Route::get('/{id}', [SupportController::class, 'show']);
        Route::post('/{id}/messages', [SupportController::class, 'sendMessage']);
    });
    
    // Post Reports
    Route::prefix('posts')->group(function () {
        Route::get('/report-reasons', [PostReportController::class, 'getReasons']);
        Route::post('/{postId}/report', [PostReportController::class, 'store']);
    });
    
    Route::prefix('reports')->group(function () {
        Route::get('/', [PostReportController::class, 'index']);
        Route::get('/{reportId}', [PostReportController::class, 'show']);
    });
    
    // Notifications
    Route::prefix('notifications')->group(function () {
        Route::get('/', [NotificationController::class, 'index']);
        Route::get('/unread-count', [NotificationController::class, 'unreadCount']);
        Route::get('/recent', [NotificationController::class, 'recent']);
        Route::get('/stats', [NotificationController::class, 'stats']);
        Route::get('/types', [NotificationController::class, 'types']);
        Route::post('/mark-all-read', [NotificationController::class, 'markAllAsRead']);
        Route::post('/mark-type-read', [NotificationController::class, 'markTypeAsRead']);
        Route::post('/{id}/mark-read', [NotificationController::class, 'markAsRead']);
        Route::delete('/{id}', [NotificationController::class, 'destroy']);
    });
});

// Broadcasting Authentication
Route::middleware('auth:sanctum')->post('/broadcasting/auth', function (Request $request) {
    return Broadcast::auth($request);
});

// Admin Routes
Route::middleware(['auth:sanctum', 'admin'])->prefix('admin')->group(function () {
    
    // Dashboard Analytics
    Route::prefix('dashboard')->group(function () {
        Route::get('/analytics', [AdminDashboardController::class, 'getDashboardAnalytics']);
        Route::get('/weekly-visitors', [AdminDashboardController::class, 'getWeeklyVisitorsData']);
        Route::get('/daily-registrations', [AdminDashboardController::class, 'getDailyRegistrationsData']);
        Route::get('/engaging-posts', [AdminDashboardController::class, 'getMostEngagingPosts']);
        Route::get('/subscription-distribution', [AdminDashboardController::class, 'getSubscriptionDistribution']);
    });
    
    // User Management
    Route::prefix('users')->group(function () {
        Route::get('/', [AdminUserController::class, 'index']);
        Route::get('/{id}', [AdminUserController::class, 'show']);
        Route::put('/{id}/subscription', [AdminUserController::class, 'updateSubscription']);
        Route::put('/{id}/status', [AdminUserController::class, 'updateStatus']);
        Route::delete('/{id}', [AdminUserController::class, 'destroy']);
        Route::post('/{id}/restore', [AdminUserController::class, 'restore']);
        Route::get('/deleted/list', [AdminUserController::class, 'getDeleted']);
        Route::post('/bulk-action', [AdminUserController::class, 'bulkAction']);
    });
    
    // Avatar Management
    Route::post('/generate-all-default-avatars', [ProfileController::class, 'generateDefaultAvatars']);
    
    // Room Management
    Route::prefix('rooms')->group(function () {
        Route::get('/', [AdminRoomController::class, 'index']);
        Route::get('/{id}', [AdminRoomController::class, 'show']);
        Route::put('/{id}', [AdminRoomController::class, 'update']);
        Route::delete('/{id}', [AdminRoomController::class, 'destroy']);
        Route::get('/statistics/overview', [AdminRoomController::class, 'getStatistics']);
        Route::post('/bulk-action', [AdminRoomController::class, 'bulkAction']);
    });
    
    // Payment Management
    Route::prefix('payments')->group(function () {
        Route::get('/', [AdminPaymentController::class, 'getAllPayments']);
        Route::get('/pending', [AdminPaymentController::class, 'getPendingPayments']);
        Route::post('/approve', [AdminPaymentController::class, 'approvePayment']);
        Route::post('/reject', [AdminPaymentController::class, 'rejectPayment']);
        Route::get('/statistics', [AdminPaymentController::class, 'getPaymentStatistics']);
        Route::get('/qr-codes', [AdminPaymentController::class, 'getQrCodes']);
        Route::get('/active-qr-code', [AdminPaymentController::class, 'getActiveQrCode']);
        Route::put('/qr-code/{id}', [AdminPaymentController::class, 'updateQrCode']);
    });
    
    Route::get('/subscriptions', [AdminPaymentController::class, 'getAllSubscriptions']);
    
    // Activity Logs
    Route::prefix('activity-logs')->group(function () {
        Route::get('/', [AdminActivityLogController::class, 'index']);
        Route::get('/stats', [AdminActivityLogController::class, 'getStats']);
        Route::get('/categories', [AdminActivityLogController::class, 'getCategories']);
        Route::get('/severities', [AdminActivityLogController::class, 'getSeverities']);
        Route::get('/recent', [AdminActivityLogController::class, 'getRecentActivity']);
        Route::post('/', [AdminActivityLogController::class, 'logActivity']);
    });
    
    // Support System
    Route::prefix('support')->group(function () {
        Route::get('/', [AdminSupportController::class, 'index']);
        Route::get('/stats', [AdminSupportController::class, 'getStats']);
        Route::get('/priorities', [AdminSupportController::class, 'getPriorities']);
        Route::get('/{id}', [AdminSupportController::class, 'show']);
        Route::put('/{id}/status', [AdminSupportController::class, 'updateStatus']);
        Route::put('/{id}/assign', [AdminSupportController::class, 'assignTicket']);
        Route::post('/{id}/reply', [AdminSupportController::class, 'sendReply']);
        Route::delete('/{id}', [AdminSupportController::class, 'destroy']);
    });
    
    // Content Moderation
    Route::prefix('content-moderation')->group(function () {
        Route::get('/', [AdminContentModerationController::class, 'index']);
        Route::get('/stats', [AdminContentModerationController::class, 'getStats']);
        Route::get('/filters', [AdminContentModerationController::class, 'getFilters']);
        Route::get('/{reportId}', [AdminContentModerationController::class, 'show']);
        Route::put('/{reportId}/status', [AdminContentModerationController::class, 'updateStatus']);
        Route::post('/{reportId}/action', [AdminContentModerationController::class, 'takeAction']);
    });
    
    // Notification Management
    Route::prefix('notifications')->group(function () {
        Route::get('/', [AdminNotificationController::class, 'index']);
        Route::post('/', [AdminNotificationController::class, 'store']);
        Route::post('/send-to-user', [AdminNotificationController::class, 'sendToUser']);
        Route::get('/stats', [AdminNotificationController::class, 'getStats']);
        Route::get('/types', [AdminNotificationController::class, 'getTypes']);
        Route::get('/target-audiences', [AdminNotificationController::class, 'getTargetAudiences']);
        Route::get('/{id}', [AdminNotificationController::class, 'show']);
        Route::delete('/{id}', [AdminNotificationController::class, 'destroy']);
        Route::post('/send-scheduled', [AdminNotificationController::class, 'sendScheduledNotifications']);
    });
});
