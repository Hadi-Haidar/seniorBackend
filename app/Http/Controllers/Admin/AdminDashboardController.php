<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Room;
use App\Models\Post;
use App\Models\Comment;
use App\Models\Subscription;
use App\Models\UserActivity;
use App\Models\PostLike;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminDashboardController extends Controller
{
    /**
     * Get dashboard analytics overview
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function getDashboardAnalytics()
    {
        // Get total users count with percentage change
        $totalUsers = User::count();
        $lastWeekUsers = User::where('created_at', '<', Carbon::now()->subWeek())->count();
        $userPercentChange = $lastWeekUsers > 0 
            ? round((($totalUsers - $lastWeekUsers) / $lastWeekUsers) * 100, 1) 
            : 100;
            
        // Get daily registrations with percentage change
        $todayRegistrations = User::whereDate('created_at', Carbon::today())->count();
        $yesterdayRegistrations = User::whereDate('created_at', Carbon::yesterday())->count();
        $registrationPercentChange = $yesterdayRegistrations > 0 
            ? round((($todayRegistrations - $yesterdayRegistrations) / $yesterdayRegistrations) * 100, 1) 
            : ($todayRegistrations > 0 ? 100 : 0);
            
        // Get total posts with percentage change
        $totalPosts = Post::count();
        $lastWeekPosts = Post::where('created_at', '<', Carbon::now()->subWeek())->count();
        $postsPercentChange = $lastWeekPosts > 0 
            ? round((($totalPosts - $lastWeekPosts) / $lastWeekPosts) * 100, 1) 
            : 100;
            
        // Get active subscriptions with percentage change
        $activeSubscriptions = Subscription::where('is_active', true)
            ->where('end_date', '>=', Carbon::today())
            ->count();
        $lastWeekSubscriptions = Subscription::where('is_active', true)
            ->where('end_date', '>=', Carbon::today()->subWeek())
            ->where('created_at', '<', Carbon::now()->subWeek())
            ->count();
        $subscriptionsPercentChange = $lastWeekSubscriptions > 0 
            ? round((($activeSubscriptions - $lastWeekSubscriptions) / $lastWeekSubscriptions) * 100, 1) 
            : ($activeSubscriptions > 0 ? 100 : 0);
            
        // Get total rooms
        $totalRooms = Room::count();
        
        // Get daily visitors (unique users with activity today)
        $dailyVisitors = UserActivity::whereDate('activity_date', Carbon::today())
            ->distinct('user_id')
            ->count('user_id');
        
        // Get top rated posts
        $topRatedPosts = Post::withCount('likes')
            ->orderBy('likes_count', 'desc')
            ->limit(45) // Limit to top 45 posts
            ->count();
            
        return response()->json([
            'success' => true,
            'data' => [
                'total_users' => [
                    'count' => $totalUsers,
                    'percent_change' => $userPercentChange,
                ],
                'daily_registrations' => [
                    'count' => $todayRegistrations,
                    'percent_change' => $registrationPercentChange,
                ],
                'total_posts' => [
                    'count' => $totalPosts,
                    'percent_change' => $postsPercentChange,
                ],
                'active_subscriptions' => [
                    'count' => $activeSubscriptions,
                    'percent_change' => $subscriptionsPercentChange,
                ],
                'total_rooms' => $totalRooms,
                'daily_visitors' => $dailyVisitors,
                'top_rated_posts' => $topRatedPosts,
            ]
        ]);
    }
    
    /**
     * Get weekly visitors data for chart
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function getWeeklyVisitorsData()
    {
        $startDate = Carbon::now()->subDays(6)->startOfDay();
        $endDate = Carbon::now()->endOfDay();
        
        $dailyVisitors = UserActivity::select(
                DB::raw('DATE(activity_date) as date'),
                DB::raw('COUNT(DISTINCT user_id) as visitors')
            )
            ->where('activity_date', '>=', $startDate)
            ->where('activity_date', '<=', $endDate)
            ->groupBy('date')
            ->orderBy('date')
            ->get();
            
        // Format data for chart
        $formattedData = [];
        $currentDate = clone $startDate;
        
        while ($currentDate <= $endDate) {
            $dateString = $currentDate->format('Y-m-d');
            $dayName = $currentDate->format('D');
            $visitors = 0;
            
            // Find matching record
            $record = $dailyVisitors->firstWhere('date', $dateString);
            if ($record) {
                $visitors = $record->visitors;
            }
            
            $formattedData[] = [
                'day' => $dayName,
                'date' => $dateString,
                'visitors' => $visitors
            ];
            
            $currentDate->addDay();
        }
        
        return response()->json([
            'success' => true,
            'data' => $formattedData
        ]);
    }
    
    /**
     * Get daily registrations data for chart
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function getDailyRegistrationsData()
    {
        $startDate = Carbon::now()->subDays(6)->startOfDay();
        $endDate = Carbon::now()->endOfDay();
        
        $dailyRegistrations = User::select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('COUNT(*) as registrations')
            )
            ->where('created_at', '>=', $startDate)
            ->where('created_at', '<=', $endDate)
            ->groupBy('date')
            ->orderBy('date')
            ->get();
            
        // Format data for chart
        $formattedData = [];
        $currentDate = clone $startDate;
        
        while ($currentDate <= $endDate) {
            $dateString = $currentDate->format('Y-m-d');
            $dayName = $currentDate->format('D');
            $registrations = 0;
            
            // Find matching record
            $record = $dailyRegistrations->firstWhere('date', $dateString);
            if ($record) {
                $registrations = $record->registrations;
            }
            
            $formattedData[] = [
                'day' => $dayName,
                'date' => $dateString,
                'registrations' => $registrations
            ];
            
            $currentDate->addDay();
        }
        
        return response()->json([
            'success' => true,
            'data' => $formattedData
        ]);
    }
    
    /**
     * Get most engaging posts
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function getMostEngagingPosts()
    {
        $posts = Post::withCount(['likes', 'comments'])
            ->with(['user:id,name,avatar', 'room:id,name'])
            ->orderByRaw('likes_count + comments_count DESC')
            ->limit(5)
            ->get()
            ->map(function ($post) {
                return [
                    'id' => $post->id,
                    'title' => $post->title,
                    'content' => \Str::limit($post->content, 100),
                    'likes' => $post->likes_count,
                    'comments' => $post->comments_count,
                    'total_engagement' => $post->likes_count + $post->comments_count,
                    'author' => [
                        'id' => $post->user->id,
                        'name' => $post->user->name,
                        'avatar' => $post->user->avatar
                    ],
                    'room' => [
                        'id' => $post->room->id,
                        'name' => $post->room->name
                    ],
                    'created_at' => $post->created_at->format('Y-m-d H:i:s')
                ];
            });
            
        return response()->json([
            'success' => true,
            'data' => $posts
        ]);
    }
    
    /**
     * Get subscription distribution data
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function getSubscriptionDistribution()
    {
        $bronzeCount = User::where('subscription_level', 'bronze')->count();
        $silverCount = User::where('subscription_level', 'silver')->count();
        $goldCount = User::where('subscription_level', 'gold')->count();
        
        return response()->json([
            'success' => true,
            'data' => [
                ['level' => 'Bronze', 'count' => $bronzeCount],
                ['level' => 'Silver', 'count' => $silverCount],
                ['level' => 'Gold', 'count' => $goldCount]
            ]
        ]);
    }
} 