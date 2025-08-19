<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\PostReport;
use App\Models\Post;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PostReportController extends Controller
{
    /**
     * Get available report reasons
     */
    public function getReasons()
    {
        return response()->json([
            'success' => true,
            'reasons' => PostReport::getReasons()
        ]);
    }

    /**
     * Report a post
     */
    public function store(Request $request, $postId)
    {
        $request->validate([
            'reason' => 'required|in:spam,inappropriate_content,false_information,other',
            'description' => 'nullable|string|max:1000'
        ]);

        try {
            // Check if post exists and is public
            $post = Post::findOrFail($postId);
            
            if ($post->visibility !== 'public') {
                return response()->json([
                    'success' => false,
                    'message' => 'Only public posts can be reported'
                ], 400);
            }

            // Check if user already reported this post
            $existingReport = PostReport::where('post_id', $postId)
                ->where('reported_by', Auth::id())
                ->first();

            if ($existingReport) {
                return response()->json([
                    'success' => false,
                    'message' => 'You have already reported this post'
                ], 400);
            }

            // Prevent users from reporting their own posts
            if ($post->user_id === Auth::id()) {
                return response()->json([
                    'success' => false,
                    'message' => 'You cannot report your own post'
                ], 400);
            }

            DB::beginTransaction();

            // Create the report
            $report = PostReport::create([
                'post_id' => $postId,
                'reported_by' => Auth::id(),
                'reason' => $request->reason,
                'description' => $request->description,
                'status' => 'pending'
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Post reported successfully. Our team will review it shortly.',
                'report' => $report
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to report post: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get user's reports
     */
    public function index(Request $request)
    {
        try {
            $reports = PostReport::with(['post', 'post.author', 'post.room'])
                ->where('reported_by', Auth::id())
                ->orderBy('created_at', 'desc')
                ->paginate($request->per_page ?? 15);

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
     * Get specific report details
     */
    public function show($reportId)
    {
        try {
            $report = PostReport::with(['post', 'post.author', 'post.room', 'reviewer'])
                ->where('reported_by', Auth::id())
                ->findOrFail($reportId);

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
}
