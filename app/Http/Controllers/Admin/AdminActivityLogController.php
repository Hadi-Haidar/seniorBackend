<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;

class AdminActivityLogController extends Controller
{
    /**
     * Get activity logs with filtering and search
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $query = ActivityLog::with('admin:id,name,email');

        // Apply filters
        if ($request->has('search') && $request->search) {
            $query->search($request->search);
        }

        if ($request->has('category') && $request->category !== 'All') {
            $query->category($request->category);
        }

        if ($request->has('severity') && $request->severity !== 'All') {
            $query->severity($request->severity);
        }

        if ($request->has('date_filter') && $request->date_filter !== 'All') {
            $query->dateRange($request->date_filter);
        }

        // Pagination and sorting
        $perPage = $request->get('per_page', 20);
        $logs = $query->orderBy('created_at', 'desc')->paginate($perPage);

        // Format the response
        $formattedLogs = $logs->getCollection()->map(function ($log) {
            return [
                'id' => $log->id,
                'admin' => $log->admin ? $log->admin->name : 'Unknown Admin',
                'admin_email' => $log->admin ? $log->admin->email : 'unknown@email.com',
                'action' => $log->action,
                'target' => $log->target,
                'details' => $log->details,
                'category' => $log->category,
                'severity' => $log->severity,
                'ip_address' => $log->ip_address,
                'timestamp' => $log->created_at->format('Y-m-d H:i:s'),
                'formatted_date' => $log->created_at->format('M d, Y'),
                'formatted_time' => $log->created_at->format('h:i A'),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'logs' => $formattedLogs,
                'pagination' => [
                    'current_page' => $logs->currentPage(),
                    'last_page' => $logs->lastPage(),
                    'per_page' => $logs->perPage(),
                    'total' => $logs->total(),
                ]
            ]
        ]);
    }

    /**
     * Get activity logs statistics
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function getStats()
    {
        // Total actions
        $totalActions = ActivityLog::count();

        // Today's actions
        $todayActions = ActivityLog::whereDate('created_at', Carbon::today())->count();

        // Critical actions (High + Critical severity)
        $criticalActions = ActivityLog::whereIn('severity', ['High', 'Critical'])->count();

        // Active admins (unique admins who performed actions)
        $activeAdmins = ActivityLog::distinct('admin_id')->count('admin_id');

        return response()->json([
            'success' => true,
            'data' => [
                'total_actions' => $totalActions,
                'today_actions' => $todayActions,
                'critical_actions' => $criticalActions,
                'active_admins' => $activeAdmins,
            ]
        ]);
    }

    /**
     * Get available categories
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function getCategories()
    {
        $categories = [
            'All',
            'Content Moderation',
            'User Management',
            'Payment Management'
        ];

        return response()->json([
            'success' => true,
            'data' => $categories
        ]);
    }

    /**
     * Get available severities
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function getSeverities()
    {
        $severities = ['All', 'Low', 'Medium', 'High', 'Critical'];

        return response()->json([
            'success' => true,
            'data' => $severities
        ]);
    }

    /**
     * Log a new admin activity
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function logActivity(Request $request)
    {
        $request->validate([
            'action' => 'required|string|max:255',
            'target' => 'required|string|max:255',
            'details' => 'nullable|string',
            'category' => 'required|in:Content Moderation,User Management,Payment Management',
            'severity' => 'required|in:Low,Medium,High,Critical',
        ]);

        $log = ActivityLog::logActivity(
            auth()->id(),
            $request->action,
            $request->target,
            $request->details,
            $request->category,
            $request->severity,
            $request->ip()
        );

        return response()->json([
            'success' => true,
            'message' => 'Activity logged successfully',
            'data' => $log
        ], 201);
    }

    /**
     * Get recent activity for dashboard
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getRecentActivity(Request $request)
    {
        $limit = $request->get('limit', 10);
        
        $recentLogs = ActivityLog::with('admin:id,name,email')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        $formattedLogs = $recentLogs->map(function ($log) {
            return [
                'id' => $log->id,
                'admin' => $log->admin ? $log->admin->name : 'Unknown Admin',
                'action' => $log->action,
                'target' => $log->target,
                'severity' => $log->severity,
                'timestamp' => $log->created_at->diffForHumans(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $formattedLogs
        ]);
    }
} 