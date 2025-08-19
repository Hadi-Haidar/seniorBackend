<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\UserActivity;
use App\Models\Post;
use App\Models\Product;
use App\Models\Order;
use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Get user activity data for dashboard
     */
    public function getActivityData()
    {
        $user = Auth::user();
        
        // Get today's activity
        $todaysActivity = UserActivity::getTodaysMinutes($user->id);
        
        // Get last activity time
        $lastActivity = UserActivity::where('user_id', $user->id)
            ->latest('last_activity_at')
            ->first();
        
        // Get weekly activity data (last 7 days)
        $weeklyData = UserActivity::where('user_id', $user->id)
            ->where('activity_date', '>=', Carbon::now()->subDays(6))
            ->orderBy('activity_date')
            ->get()
            ->keyBy(function ($item) {
                return $item->activity_date->format('Y-m-d');
            });
        
        // Create array with all 7 days
        $weeklyChart = [];
        $days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        
        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i);
            $dateKey = $date->format('Y-m-d');
            $dayName = $days[$date->dayOfWeek == 0 ? 6 : $date->dayOfWeek - 1]; // Adjust Sunday to be last
            
            $weeklyChart[] = [
                'day' => $dayName,
                'minutes' => $weeklyData->get($dateKey)->total_minutes ?? 0
            ];
        }
        
        return response()->json([
            'success' => true,
            'activity' => [
                'minutesToday' => $todaysActivity,
                'lastActive' => $lastActivity ? $lastActivity->last_activity_at : null,
                'weeklyData' => $weeklyChart
            ]
        ]);
    }
    
    /**
     * Get user posts summary for dashboard
     */
    public function getPostsSummary()
    {
        $user = Auth::user();
        
        // Get ALL posts from user (these are room posts)
        $roomPosts = Post::where('user_id', $user->id)->count();
        
        // Get published posts (public posts - subset of room posts)
        $publicPosts = Post::where('user_id', $user->id)
            ->whereNotNull('published_at')
            ->count();
        
        $totalPosts = $roomPosts;
        
        // Get total likes across all user's posts
        $totalLikes = Post::where('user_id', $user->id)
            ->withCount('likes')
            ->get()
            ->sum('likes_count');
        
        // Get recent posts with likes count
        $recentPosts = Post::where('user_id', $user->id)
            ->with(['likes'])
            ->latest()
            ->take(5)
            ->get()
            ->map(function ($post) {
                $isPublished = !is_null($post->published_at);
                $isPublic = $post->visibility === 'public';
                
                return [
                    'id' => $post->id,
                    'title' => $post->title,
                    'status' => $isPublished ? 'published' : 'draft',
                    'visibility' => $post->visibility,
                    'combined_status' => ($isPublished && $isPublic) ? 'in_public' : 'in_room',
                    'likes' => $post->likes()->count(),
                    'created_at' => $post->created_at
                ];
            });
        
        return response()->json([
            'success' => true,
            'posts' => [
                'total' => $totalPosts,
                'roomPosts' => $roomPosts,
                'publicPosts' => $publicPosts,
                'totalLikes' => $totalLikes,
                'recent' => $recentPosts
            ]
        ]);
    }
    
    /**
     * Get user store summary for dashboard
     * This combines products from private rooms and public store
     */
    public function getStoreSummary()
    {
        $user = Auth::user();
        
        // Get user's rooms
        $userRooms = Room::where('owner_id', $user->id)->pluck('id');
        
        // Get ALL products from user's rooms (these are room products)
        $roomProducts = Product::whereIn('room_id', $userRooms)->count();
        
        // Get products from user's rooms with public visibility (store products)
        // These are a subset of room products that are visible in the store
        $storeProducts = Product::whereIn('room_id', $userRooms)
            ->where('visibility', 'public')
            ->count();
        
        $totalProducts = $roomProducts;
        
        // Get orders for user's products
        $totalOrders = Order::whereHas('product', function ($query) use ($userRooms) {
            $query->whereIn('room_id', $userRooms);
        })->count();
        
        // Get orders count for this month
        $monthlyOrders = Order::whereHas('product', function ($query) use ($userRooms) {
            $query->whereIn('room_id', $userRooms);
        })->whereMonth('created_at', Carbon::now()->month)
          ->whereYear('created_at', Carbon::now()->year)
          ->count();
        
        // Calculate revenue this month
        $monthlyRevenue = Order::whereHas('product', function ($query) use ($userRooms) {
            $query->whereIn('room_id', $userRooms);
        })->whereMonth('created_at', Carbon::now()->month)
          ->whereYear('created_at', Carbon::now()->year)
          ->where('status', '!=', 'cancelled')
          ->sum('total_price');
        
        // Get low stock products (stock <= 5)
        $lowStockCount = Product::whereIn('room_id', $userRooms)
            ->where('stock', '<=', 5)
            ->where('stock', '>', 0)
            ->count();
        
        // Get recent orders
        $recentOrders = Order::with(['product', 'buyer'])
            ->whereHas('product', function ($query) use ($userRooms) {
                $query->whereIn('room_id', $userRooms);
            })
            ->latest()
            ->take(5)
            ->get()
            ->map(function ($order) {
                return [
                    'id' => $order->id,
                    'product_name' => $order->product->name,
                    'buyer_name' => $order->buyer->name,
                    'total_price' => $order->total_price,
                    'status' => $order->status,
                    'created_at' => $order->created_at
                ];
            });
        
        return response()->json([
            'success' => true,
            'store' => [
                'totalProducts' => $totalProducts,
                'roomProducts' => $roomProducts,
                'storeProducts' => $storeProducts,
                'orders' => $totalOrders,
                'monthlyOrders' => $monthlyOrders,
                'revenue' => number_format($monthlyRevenue, 2), // Already in dollars
                'lowStock' => $lowStockCount,
                'recentOrders' => $recentOrders
            ]
        ]);
    }
    
    /**
     * Update user activity (called from frontend activity tracker)
     */
    public function updateActivity(Request $request)
    {
        $user = Auth::user();
        $minutesToAdd = $request->input('minutes', 1);
        
        UserActivity::updateActivity($user->id, $minutesToAdd);
        
        return response()->json([
            'success' => true,
            'message' => 'Activity updated successfully'
        ]);
    }
    
    /**
     * Get complete dashboard data in one request
     */
    public function getDashboardData()
    {
        $activityData = $this->getActivityData()->getData();
        $postsData = $this->getPostsSummary()->getData();
        $storeData = $this->getStoreSummary()->getData();
        
        return response()->json([
            'success' => true,
            'activity' => $activityData->activity,
            'posts' => $postsData->posts,
            'store' => $storeData->store
        ]);
    }
} 