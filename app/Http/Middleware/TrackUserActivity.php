<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\UserActivity;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class TrackUserActivity
{
    /**
     * Handle an incoming request and track user activity.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        // Only track for authenticated users
        if (Auth::check()) {
            $user = Auth::user();
            
            // Check if this is an API request that should be tracked
            $shouldTrack = $this->shouldTrackRequest($request);
            
            if ($shouldTrack) {
                try {
                    // Get or create today's activity record
                    $today = Carbon::today();
                    $activity = UserActivity::firstOrCreate([
                        'user_id' => $user->id,
                        'activity_date' => $today
                    ], [
                        'total_minutes' => 0,
                        'last_activity_at' => now()
                    ]);

                    // Only add 1 minute if last activity was more than 1 minute ago
                    // This prevents spam requests from giving too much activity time
                    $lastActivity = $activity->last_activity_at;
                    $minutesSinceLastActivity = $lastActivity ? $lastActivity->diffInMinutes(now()) : 60;
                    
                    if ($minutesSinceLastActivity >= 1) {
                        $activity->increment('total_minutes', 1);
                        $activity->update(['last_activity_at' => now()]);
                    }
                } catch (\Exception $e) {
                    // Log error but don't break the request
                    Log::error('Failed to track user activity: ' . $e->getMessage(), [
                        'user_id' => $user->id,
                        'error' => $e->getTraceAsString()
                    ]);
                }
            }
        }

        return $response;
    }

    /**
     * Determine if the request should be tracked for activity.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return bool
     */
    private function shouldTrackRequest(Request $request)
    {
        // Only track API requests (not static assets)
        if (!$request->is('api/*')) {
            return false;
        }

        // Don't track activity recording endpoint to avoid recursion
        if ($request->is('api/user/coins/activity/record')) {
            return false;
        }

        // Don't track login/logout requests
        if ($request->is('api/auth/*')) {
            return false;
        }

        // Track GET requests (viewing pages/data) and POST requests (interactions)
        return in_array($request->method(), ['GET', 'POST', 'PUT', 'PATCH', 'DELETE']);
    }
} 