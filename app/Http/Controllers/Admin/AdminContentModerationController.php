<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PostReport;
use App\Models\Post;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AdminContentModerationController extends Controller
{
    /**
     * Get all post reports with filtering and pagination
     */
    public function index(Request $request)
    {
        try {
            $query = PostReport::with([
                'post' => function($query) {
                    $query->with(['author:id,name,email', 'room:id,name']);
                },
                'reporter:id,name,email',
                'reviewer:id,name'
            ]);

            // Filter by status
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            // Filter by reason
            if ($request->filled('reason')) {
                $query->where('reason', $request->reason);
            }

            // Filter by severity
            if ($request->filled('severity')) {
                $severityReasons = [
                    'high' => ['inappropriate_content'],
                    'medium' => ['false_information', 'spam'],
                    'low' => ['other']
                ];
                
                if (isset($severityReasons[$request->severity])) {
                    $query->whereIn('reason', $severityReasons[$request->severity]);
                }
            }

            // Search functionality
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('description', 'LIKE', "%{$search}%")
                      ->orWhereHas('post', function($postQuery) use ($search) {
                          $postQuery->where('title', 'LIKE', "%{$search}%")
                                   ->orWhere('content', 'LIKE', "%{$search}%");
                      })
                      ->orWhereHas('reporter', function($userQuery) use ($search) {
                          $userQuery->where('name', 'LIKE', "%{$search}%")
                                   ->orWhere('email', 'LIKE', "%{$search}%");
                      });
                });
            }

            // Order by priority (high severity first) and creation date
            $query->orderByRaw("
                CASE 
                    WHEN reason IN ('inappropriate_content') THEN 1
                    WHEN reason IN ('false_information', 'spam') THEN 2
                    ELSE 3
                END
            ")
            ->orderBy('created_at', 'desc');

            $reports = $query->paginate($request->per_page ?? 15);

            // Add severity to each report
            $reports->getCollection()->transform(function ($report) {
                $report->severity = $report->severity;
                return $report;
            });

            return response()->json([
                'success' => true,
                'reports' => $reports
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch reports: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get report statistics for dashboard
     */
    public function getStats()
    {
        try {
            $stats = [
                'total' => PostReport::count(),
                'pending' => PostReport::where('status', 'pending')->count(),
                'under_review' => PostReport::where('status', 'reviewed')->count(),
                'resolved' => PostReport::where('status', 'resolved')->count(),
                'high_priority' => PostReport::whereIn('reason', ['inappropriate_content'])
                    ->whereIn('status', ['pending', 'reviewed'])
                    ->count(),
                'by_reason' => PostReport::select('reason', DB::raw('count(*) as count'))
                    ->groupBy('reason')
                    ->pluck('count', 'reason'),
                'recent_reports' => PostReport::with(['post', 'reporter:id,name'])
                    ->latest()
                    ->limit(5)
                    ->get()
            ];

            return response()->json([
                'success' => true,
                'stats' => $stats
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch statistics: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update report status
     */
    public function updateStatus(Request $request, $reportId)
    {
        $request->validate([
            'status' => 'required|in:pending,reviewed,resolved,dismissed'
        ]);

        try {
            $report = PostReport::findOrFail($reportId);

            DB::beginTransaction();

            $report->update([
                'status' => $request->status,
                'reviewed_by' => Auth::id()
            ]);

            DB::commit();

            // Reload with relationships
            $report->load(['post', 'reporter:id,name', 'reviewer:id,name']);

            return response()->json([
                'success' => true,
                'message' => 'Report status updated successfully',
                'report' => $report
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to update report status: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Take action on reported post (remove or make private)
     */
    public function takeAction(Request $request, $reportId)
    {
        $request->validate([
            'action' => 'required|in:remove_post,make_private,no_action'
        ]);

        try {
            $report = PostReport::with('post')->findOrFail($reportId);
            $post = $report->post;

            if (!$post) {
                return response()->json([
                    'success' => false,
                    'message' => 'Associated post not found'
                ], 404);
            }

            DB::beginTransaction();

            $actionTaken = false;
            $actionMessage = '';

            switch ($request->action) {
                case 'remove_post':
                    // Delete the post and its associated media
                    $post->media()->delete();
                    $post->likes()->delete();
                    $post->comments()->delete();
                    $post->delete();
                    
                    $actionTaken = true;
                    $actionMessage = 'Post removed successfully';
                    break;

                case 'make_private':
                    // Change post visibility to private
                    $post->update(['visibility' => 'private']);
                    
                    $actionTaken = true;
                    $actionMessage = 'Post made private successfully';
                    break;

                case 'no_action':
                    $actionMessage = 'No action taken on the post';
                    break;
            }

            // Update report status and save the action taken
            $report->update([
                'status' => 'resolved',
                'reviewed_by' => Auth::id(),
                'admin_action' => $request->action
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => $actionMessage,
                'action_taken' => $actionTaken,
                'report' => $report->fresh(['post', 'reporter:id,name', 'reviewer:id,name'])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to take action: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a specific report
     */
    public function show($reportId)
    {
        try {
            $report = PostReport::with([
                'post' => function($query) {
                    $query->with(['author:id,name,email', 'room:id,name', 'media']);
                },
                'reporter:id,name,email',
                'reviewer:id,name'
            ])->findOrFail($reportId);

            // Add severity
            $report->severity = $report->severity;

            return response()->json([
                'success' => true,
                'report' => $report
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Report not found'
            ], 404);
        }
    }

    /**
     * Get available reasons and statuses for filters
     */
    public function getFilters()
    {
        return response()->json([
            'success' => true,
            'filters' => [
                'reasons' => PostReport::getReasons(),
                'statuses' => PostReport::getStatuses(),
                'severities' => [
                    'high' => 'High Priority',
                    'medium' => 'Medium Priority', 
                    'low' => 'Low Priority'
                ]
            ]
        ]);
    }
}
