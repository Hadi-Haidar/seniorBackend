<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\Post;
use App\Models\Comment;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Models\PostMedia;
use App\Services\NotificationService;

class PostController extends Controller
{
    protected $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Create a new post with media
     * 
     * @param Request $request
     * @param int $roomId
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request, $roomId)
    {
        $room = Room::findOrFail($roomId);
        $user = Auth::user();
        
        // Check if user is owner or moderator
        $userMembership = RoomMember::where('room_id', $roomId)
            ->where('user_id', $user->id)
            ->first();
            
        if (!$userMembership || 
            ($userMembership->role !== 'moderator' && $room->owner_id !== $user->id)) {
            return response()->json([
                'success' => false,
                'message' => 'Only room owners and moderators can create posts'
            ], 403);
        }

        // Validate post data
        $validationRules = [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'visibility' => 'required|in:private,public',
            'is_featured' => 'boolean',
        ];

        // Add scheduled_at validation with proper timezone handling
        if ($request->has('scheduled_at') && $request->scheduled_at) {
            // Parse the input datetime as Beirut time and compare with current Beirut time
            $scheduledTime = Carbon::createFromFormat('Y-m-d\TH:i', $request->scheduled_at, 'Asia/Beirut');
            $currentTime = Carbon::now('Asia/Beirut');
            
            if ($scheduledTime->lte($currentTime)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Scheduled time must be in the future (Beirut time). Current time: ' . 
                                $currentTime->format('Y-m-d H:i') . ', Scheduled: ' . 
                                $scheduledTime->format('Y-m-d H:i')
                ], 422);
            }
        }

        // Only add media validation if media files are present
        if ($request->hasFile('media')) {
            $validationRules['media'] = 'nullable|array|max:10';
            $validationRules['media.*'] = 'file|max:51200|mimes:jpeg,png,jpg,gif,pdf,mp4,avi,mov,wmv,flv,webm,mkv,mp3,wav,ogg,m4a'; // 50MB max with specific video/audio types
            $validationRules['media_types'] = 'nullable|array';
            $validationRules['media_types.*'] = 'nullable|in:image,pdf,video,voice';
        }

        $request->validate($validationRules);

        // Check visibility permissions
        if ($request->visibility === 'public') {
            // Only room owner can make public posts
            if ($room->owner_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only room owners can create public posts'
                ], 403);
            }

            // Owner must be Silver or Gold for public posts
            if (!in_array($user->subscription_level, ['silver', 'gold'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Public posts are only available for Silver and Gold subscribers'
                ], 403);
            }
        }

        try {
            DB::beginTransaction();
            

            
            // Create post
            $post = Post::create([
                'room_id' => $roomId,
                'user_id' => $user->id,
                'title' => $request->title,
                'content' => $request->content,
                'visibility' => $request->visibility,
                'is_featured' => $request->input('is_featured', false),
                'scheduled_at' => $request->scheduled_at ? 
                    Carbon::createFromFormat('Y-m-d\TH:i', $request->scheduled_at, 'Asia/Beirut') : 
                    null,
                'published_at' => $request->visibility === 'public' ? now() : null
            ]);
            

            
            // Track uploaded files for cleanup if needed
            $uploadedFiles = [];
            
            // Handle media uploads
            if ($request->hasFile('media')) {
                foreach ($request->file('media') as $index => $mediaFile) {
                    try {
                        $mediaType = $request->media_types[$index];
                        $path = $mediaFile->store("posts/{$post->id}/{$mediaType}", 'public');
                        $uploadedFiles[] = $path;
                        
                        PostMedia::create([
                            'post_id' => $post->id,
                            'media_type' => $mediaType,
                            'file_path' => $path
                        ]);
                    } catch (\Exception $e) {
                        // Log the specific file upload error
                        \Log::error("Failed to upload media file", [
                            'post_id' => $post->id,
                            'media_type' => $mediaType ?? 'unknown',
                            'error' => $e->getMessage()
                        ]);
                        throw $e;
                    }
                }
            }
            
            DB::commit();

            // Load media and author relationships with avatar data
            $post->load(['media', 'author:id,name,avatar,subscription_level', 'room:id,name,owner_id']);
            
            // Add like status for consistency with other endpoints
            $user = Auth::user();
            $post->is_liked_by_user = $post->isLikedBy($user->id);
            $post->likes_count = $post->likes()->count();

            return response()->json([
                'success' => true,
                'message' => 'Post created successfully with media',
                'post' => $post
            ], 201);
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            // Clean up only the files that were uploaded in this request
            foreach ($uploadedFiles as $path) {
                Storage::disk('public')->delete($path);
            }
            
            \Log::error("Failed to create post with media", [
                'room_id' => $roomId,
                'user_id' => Auth::id(),
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to create post',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get posts in a room
     */
    public function index($roomId)
    {
        try {
            $room = Room::findOrFail($roomId);
            $user = Auth::user();



            // Check if user is a member of the room
            $isMember = RoomMember::where('room_id', $roomId)
                ->where('user_id', $user->id)
                ->where('status', 'approved')
                ->exists();



            if (!$isMember && $room->owner_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'You must be a member of this room to view posts'
                ], 403);
            }

            // Get posts that are either:
            // 1. Already published (no schedule)
            // 2. Scheduled and past their schedule time
            $posts = Post::where('room_id', $roomId)
                ->where(function($query) {
                    $query->whereNull('scheduled_at')
                        ->orWhere('scheduled_at', '<=', now());
                })
                ->with(['author:id,name,avatar,subscription_level', 'media', 'room:id,name,owner_id'])
                ->withCount('likes')
                ->orderBy('created_at', 'desc')
                ->paginate(15);

            // Add user like status for each post
            $posts->getCollection()->transform(function ($post) use ($user) {
                $post->is_liked_by_user = $post->isLikedBy($user->id);
                return $post;
            });



            return response()->json([
                'success' => true,
                'posts' => $posts
            ]);

        } catch (\Exception $e) {
            \Log::error("Error fetching posts", [
                'room_id' => $roomId,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch posts',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a specific post
     */
    public function show($roomId, $postId)
    {
        $room = Room::findOrFail($roomId);
        $post = Post::where('room_id', $roomId)
            ->where('id', $postId)
            ->with(['author:id,name,avatar,subscription_level', 'room:id,name,owner_id'])
            ->firstOrFail();
        
        $user = Auth::user();

        // If post is private, check membership
        if ($post->visibility === 'private') {
            $isMember = RoomMember::where('room_id', $roomId)
                ->where('user_id', $user->id)
                ->where('status', 'approved')
                ->exists();

            if (!$isMember && $room->owner_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'You must be a member of this room to view private posts'
                ], 403);
            }
        }
        // If post is public, allow access to everyone

        return response()->json([
            'success' => true,
            'post' => $post
        ]);
    }

    /**
     * Update post with media
     */
    public function update(Request $request, $roomId, $postId)
    {
        $room = Room::findOrFail($roomId);
        $post = Post::where('room_id', $roomId)->findOrFail($postId);
        $user = Auth::user();

        // Check permissions
        if ($post->user_id !== $user->id && $room->owner_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'You can only edit your own posts or posts in rooms you own'
            ], 403);
        }

        // Validate request
        $validationRules = [
            'title' => 'sometimes|string|max:255',
            'content' => 'sometimes|string',
            'visibility' => 'sometimes|in:private,public',
            'is_featured' => 'boolean',
            // Validate media to delete
            'delete_media' => 'nullable|array',
            'delete_media.*' => 'exists:post_media,id'
        ];

        // Add scheduled_at validation with proper timezone handling for updates
        if ($request->has('scheduled_at') && $request->scheduled_at) {
            // Parse the input datetime as Beirut time and compare with current Beirut time
            $scheduledTime = Carbon::createFromFormat('Y-m-d\TH:i', $request->scheduled_at, 'Asia/Beirut');
            $currentTime = Carbon::now('Asia/Beirut');
            
            if ($scheduledTime->lte($currentTime)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Scheduled time must be in the future (Beirut time). Current time: ' . 
                                $currentTime->format('Y-m-d H:i') . ', Scheduled: ' . 
                                $scheduledTime->format('Y-m-d H:i')
                ], 422);
            }
        }

        // Only add media validation if media files are present
        if ($request->hasFile('media')) {
            $validationRules['media'] = 'nullable|array|max:10';
            $validationRules['media.*'] = 'file|max:51200|mimes:jpeg,png,jpg,gif,pdf,mp4,avi,mov,wmv,flv,webm,mkv,mp3,wav,ogg,m4a'; // 50MB max with specific video/audio types
            $validationRules['media_types'] = 'nullable|array';
            $validationRules['media_types.*'] = 'nullable|in:image,pdf,video,voice';
        }

        $request->validate($validationRules);

        // Check visibility permissions
        if ($request->has('visibility') && $request->visibility === 'public') {
            // Only room owner can make public posts
            if ($room->owner_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only room owners can create public posts'
                ], 403);
            }

            // Owner must be Silver or Gold for public posts
            if (!in_array($user->subscription_level, ['silver', 'gold'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Public posts are only available for Silver and Gold subscribers'
                ], 403);
            }
        }

        try {
            DB::beginTransaction();

            // Update post
            $updateData = $request->only([
                'title',
                'content',
                'visibility',
                'is_featured'
            ]);
            
            // Handle scheduled_at with proper timezone conversion
            if ($request->has('scheduled_at')) {
                $updateData['scheduled_at'] = $request->scheduled_at ? 
                    Carbon::createFromFormat('Y-m-d\TH:i', $request->scheduled_at, 'Asia/Beirut') : 
                    null;
            }
            
            // Handle published_at when visibility changes to public
            if ($request->has('visibility') && $request->visibility === 'public' && $post->visibility !== 'public') {
                $updateData['published_at'] = now();
            } elseif ($request->has('visibility') && $request->visibility === 'private') {
                $updateData['published_at'] = null;
            }
            
            $post->update($updateData);

            // Handle media deletions
            if ($request->has('delete_media')) {
                $mediaToDelete = PostMedia::whereIn('id', $request->delete_media)
                    ->where('post_id', $post->id)
                    ->get();

                foreach ($mediaToDelete as $media) {
                    Storage::disk('public')->delete($media->file_path);
                    $media->delete();
                }
            }

            // Handle new media uploads
            if ($request->hasFile('media')) {
                foreach ($request->file('media') as $index => $mediaFile) {
                    $mediaType = $request->media_types[$index];
                    $path = $mediaFile->store("posts/{$post->id}/{$mediaType}", 'public');
                    
                    PostMedia::create([
                        'post_id' => $post->id,
                        'media_type' => $mediaType,
                        'file_path' => $path
                    ]);
                }
            }

            DB::commit();

            // Load updated media and author relationships with avatar data
            $post->load(['media', 'author:id,name,avatar,subscription_level', 'room:id,name,owner_id']);
            
            // Add like status for consistency with other endpoints
            $user = Auth::user();
            $post->is_liked_by_user = $post->isLikedBy($user->id);
            $post->likes_count = $post->likes()->count();

            return response()->json([
                'success' => true,
                'message' => 'Post updated successfully',
                'post' => $post
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update post',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete post and its media
     */
    public function destroy($roomId, $postId)
    {
        $room = Room::findOrFail($roomId);
        $post = Post::where('room_id', $roomId)->findOrFail($postId);
        $user = Auth::user();

        if ($post->user_id !== $user->id && $room->owner_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'You can only delete your own posts or posts in rooms you own'
            ], 403);
        }

        try {
            DB::beginTransaction();

            // Delete all media files
            Storage::disk('public')->deleteDirectory("posts/{$post->id}");
            
            // Delete post (this will also delete related media records due to cascade)
            $post->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Post and associated media deleted successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete post',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get comments for a post
     */
    public function indexComments($roomId, $postId)
    {
        $room = Room::findOrFail($roomId);
        $post = Post::where('room_id', $roomId)->findOrFail($postId);
        $user = Auth::user();

        // If post is public, allow anyone to view comments
        if ($post->visibility === 'public') {
            $comments = $post->comments()
                ->with('user:id,name,avatar,subscription_level')
                ->orderBy('created_at', 'desc')
                ->paginate(20);

            return response()->json([
                'success' => true,
                'comments' => $comments
            ]);
        }

        // For private posts, check membership
        $isMember = RoomMember::where('room_id', $roomId)
            ->where('user_id', $user->id)
            ->where('status', 'approved')
            ->exists();

        if (!$isMember && $room->owner_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'You must be a member of this room to view comments on private posts'
            ], 403);
        }

        $comments = $post->comments()
            ->with('user:id,name,avatar,subscription_level')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'comments' => $comments
        ]);
    }

    /**
     * Add a comment to a post
     */
    public function storeComment(Request $request, $roomId, $postId)
    {
        $room = Room::findOrFail($roomId);
        $post = Post::where('room_id', $roomId)->findOrFail($postId);
        $user = Auth::user();

        // For public posts, allow any authenticated user to comment
        if ($post->visibility === 'public') {
            // Validate request
            $request->validate([
                'content' => 'required|string|max:1000'
            ]);

            try {
                DB::beginTransaction();

                $comment = Comment::create([
                    'post_id' => $post->id,
                    'user_id' => $user->id,
                    'content' => $request->content
                ]);

                // Load user relationship for response
                $comment->load('user:id,name,avatar,subscription_level');

                // Send notification to post author
                try {
                    $this->notificationService->sendPostCommentNotification($post, $user, $comment);
                } catch (\Exception $e) {
                    \Log::error('Failed to send comment notification', [
                        'post_id' => $post->id,
                        'commenter_id' => $user->id,
                        'error' => $e->getMessage()
                    ]);
                    // Don't fail the comment operation if notification fails
                }

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Comment added successfully',
                    'comment' => $comment
                ], 201);

            } catch (\Exception $e) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to add comment',
                    'error' => $e->getMessage()
                ], 500);
            }
        }

        // For private posts, check membership
        $isMember = RoomMember::where('room_id', $roomId)
            ->where('user_id', $user->id)
            ->where('status', 'approved')
            ->exists();

        if (!$isMember && $room->owner_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'You must be a member of this room to comment on private posts'
            ], 403);
        }

        // Validate request
        $request->validate([
            'content' => 'required|string|max:1000'
        ]);

        try {
            DB::beginTransaction();

            // Create comment
            $comment = Comment::create([
                'post_id' => $post->id,
                'user_id' => $user->id,
                'content' => $request->content
            ]);

            // Load user relationship for response
            $comment->load('user:id,name,avatar,subscription_level');

            // Send notification to post author
            try {
                $this->notificationService->sendPostCommentNotification($post, $user, $comment);
            } catch (\Exception $e) {
                \Log::error('Failed to send comment notification', [
                    'post_id' => $post->id,
                    'commenter_id' => $user->id,
                    'error' => $e->getMessage()
                ]);
                // Don't fail the comment operation if notification fails
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Comment added successfully',
                'comment' => $comment
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to add comment',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update a comment
     * 
     * @param Request $request
     * @param int $roomId
     * @param int $postId
     * @param int $commentId
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateComment(Request $request, $roomId, $postId, $commentId)
    {
        $room = Room::findOrFail($roomId);
        $post = Post::where('room_id', $roomId)->findOrFail($postId);
        $comment = Comment::where('post_id', $postId)->findOrFail($commentId);
        $user = Auth::user();

        // Check if user can access the post
        // Allow if: post is public OR user is member/owner
        if ($post->visibility === 'private') {
            $isMember = RoomMember::where('room_id', $roomId)
                ->where('user_id', $user->id)
                ->where('status', 'approved')
                ->exists();

            if (!$isMember && $room->owner_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'You must be a member of this room to edit comments on private posts'
                ], 403);
            }
        }

        // Check if user owns the comment
        if ($comment->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'You can only edit your own comments'
            ], 403);
        }

        // Validate request
        $request->validate([
            'content' => 'required|string|max:1000'
        ]);

        try {
            DB::beginTransaction();

            // Update comment
            $comment->update([
                'content' => $request->content
            ]);

            // Load user relationship for response
            $comment->load('user:id,name,avatar,subscription_level');

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Comment updated successfully',
                'comment' => $comment
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update comment',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a comment
     * 
     * @param int $roomId
     * @param int $postId
     * @param int $commentId
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroyComment($roomId, $postId, $commentId)
    {
        $room = Room::findOrFail($roomId);
        $post = Post::where('room_id', $roomId)->findOrFail($postId);
        $comment = Comment::where('post_id', $postId)->findOrFail($commentId);
        $user = Auth::user();

        // Check if user can access the post
        // Allow if: post is public OR user is member/owner
        if ($post->visibility === 'private') {
            $isMember = RoomMember::where('room_id', $roomId)
                ->where('user_id', $user->id)
                ->where('status', 'approved')
                ->exists();

            if (!$isMember && $room->owner_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'You must be a member of this room to delete comments on private posts'
                ], 403);
            }
        }

        // Check if user owns the comment or is room owner
        if ($comment->user_id !== $user->id && $room->owner_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'You can only delete your own comments or comments in rooms you own'
            ], 403);
        }

        try {
            DB::beginTransaction();

            // Delete comment
            $comment->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Comment deleted successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete comment',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function handleMediaUpload($mediaFile, $mediaType, $postId)
    {
        // Check disk space
        $this->checkDiskSpace($mediaFile);
        
        // Sanitize and generate unique filename
        $fileName = $this->sanitizeFileName($mediaFile->getClientOriginalName());
        $fileName = uniqid() . '_' . $fileName;
        
        try {
            // Store file
            $path = $mediaFile->storeAs(
                "posts/{$postId}/{$mediaType}",
                $fileName,
                'public'
            );
            
            // Create media record
            return PostMedia::create([
                'post_id' => $postId,
                'media_type' => $mediaType,
                'file_path' => $path
            ]);
            
        } catch (\Exception $e) {
            // Clean up on failure
            if (isset($path)) {
                Storage::disk('public')->delete($path);
            }
            throw $e;
        }
    }

    private function checkDiskSpace($file)
    {
        $freeSpace = disk_free_space(Storage::disk('public')->getDriver()->getAdapter()->getPathPrefix());
        if ($freeSpace < ($file->getSize() * 1.5)) { // 50% buffer
            throw new \Exception('Insufficient storage space');
        }
    }

    private function sanitizeFileName($fileName)
    {
        return preg_replace('/[^a-zA-Z0-9._-]/', '', $fileName);
    }

    /**
     * Get all public posts across all rooms (only shows posts less than 24 hours old)
     */
    public function publicPosts(Request $request)
    {
        $user = Auth::user();
        
        $posts = Post::where('visibility', 'public')
            ->where('published_at', '>=', now()->subHours(24)) // Only posts published in last 24 hours
            ->whereNotNull('published_at')
            ->where(function($query) {
                $query->whereNull('scheduled_at')
                    ->orWhere('scheduled_at', '<=', now());
            })
            ->with(['author:id,name,avatar,subscription_level', 'room:id,name,owner_id', 'media'])
            ->withCount('likes')
            ->orderBy('published_at', 'desc')
            ->paginate(15);

        // Add user like status for each post if user is authenticated
        if ($user) {
            $posts->getCollection()->transform(function ($post) use ($user) {
                $post->is_liked_by_user = $post->isLikedBy($user->id);
                return $post;
            });
        }

        return response()->json([
            'success' => true,
            'posts' => $posts
        ]);
    }

    /**
     * Get public posts for a specific room (only shows posts less than 24 hours old)
     */
    public function roomPublicPosts($roomId)
    {
        $room = Room::findOrFail($roomId);
        
        $posts = Post::where('room_id', $roomId)
            ->where('visibility', 'public')
            ->where('published_at', '>=', now()->subHours(24)) // Only posts published in last 24 hours
            ->whereNotNull('published_at')
            ->where(function($query) {
                $query->whereNull('scheduled_at')
                    ->orWhere('scheduled_at', '<=', now());
            })
            ->with(['author:id,name,avatar,subscription_level', 'room:id,name,owner_id', 'media'])
            ->orderBy('published_at', 'desc')
            ->paginate(15);

        return response()->json([
            'success' => true,
            'room' => $room->only(['id', 'name']),
            'posts' => $posts
        ]);
    }

    /**
     * Get featured public posts (only shows posts less than 24 hours old)
     */
    public function featuredPublicPosts()
    {
        $posts = Post::where('visibility', 'public')
            ->where('is_featured', true)
            ->where('published_at', '>=', now()->subHours(24)) // Only posts published in last 24 hours
            ->whereNotNull('published_at')
            ->where(function($query) {
                $query->whereNull('scheduled_at')
                    ->orWhere('scheduled_at', '<=', now());
            })
            ->with(['author:id,name,avatar,subscription_level', 'room:id,name,owner_id', 'media'])
            ->orderBy('published_at', 'desc')
            ->paginate(15);

        return response()->json([
            'success' => true,
            'posts' => $posts
        ]);
    }

    /**
     * Get comments for public posts (no auth required)
     */
    public function publicComments($roomId, $postId)
    {
        $post = Post::where('room_id', $roomId)->findOrFail($postId);

        // Check if post is public
        if ($post->visibility !== 'public') {
            return response()->json([
                'success' => false,
                'message' => 'This post is not public'
            ], 403);
        }

        $comments = $post->comments()
            ->with('user:id,name,avatar,subscription_level')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'comments' => $comments
        ]);
    }

    /**
     * Get comments for room members (auth required)
     */
    public function memberComments($roomId, $postId)
    {
        $room = Room::findOrFail($roomId);
        $post = Post::where('room_id', $roomId)->findOrFail($postId);
        $user = Auth::user();

        // Check if user is a member or owner
        $isMember = RoomMember::where('room_id', $roomId)
            ->where('user_id', $user->id)
            ->where('status', 'approved')
            ->exists();

        if (!$isMember && $room->owner_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'You must be a member of this room to view these comments'
            ], 403);
        }

        $comments = $post->comments()
            ->with('user:id,name,avatar,subscription_level')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'post' => [
                'id' => $post->id,
                'title' => $post->title,
                'visibility' => $post->visibility
            ],
            'comments' => $comments,
            'user_role' => $room->owner_id === $user->id ? 'owner' : 'member'
        ]);
    }

    /**
     * Check room membership
     */
    public function checkMembership($roomId)
    {
        try {
            $room = Room::findOrFail($roomId);
            $user = Auth::user();

            // Check if user is a member of the room
            $isMember = RoomMember::where('room_id', $roomId)
                ->where('user_id', $user->id)
                ->where('status', 'approved')
                ->exists();

            \Log::info("Membership check result", [
                'is_member' => $isMember,
                'is_owner' => $room->owner_id === $user->id
            ]);

            return response()->json([
                'success' => true,
                'is_member' => $isMember,
                'is_owner' => $room->owner_id === $user->id
            ]);

        } catch (\Exception $e) {
            \Log::error('Error checking room membership', [
                'room_id' => $roomId,
                'user_id' => Auth::id(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to check membership',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Like a post
     */
    public function likePost($roomId, $postId)
    {
        try {
            \Log::info("👍 Like post request", [
                'room_id' => $roomId,
                'post_id' => $postId,
                'user_id' => Auth::id()
            ]);

            $room = Room::findOrFail($roomId);
            $post = Post::where('room_id', $roomId)->findOrFail($postId);
            $user = Auth::user();

            // Check permissions based on post visibility
            if ($post->visibility === 'private') {
                // For private posts, user must be room member or owner
                $isMember = RoomMember::where('room_id', $roomId)
                    ->where('user_id', $user->id)
                    ->where('status', 'approved')
                    ->exists();

                if (!$isMember && $room->owner_id !== $user->id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'You must be a member of this room to like private posts'
                    ], 403);
                }
            }
            // For public posts, any authenticated user can like

            // Check if user already liked this post
            $existingLike = \App\Models\PostLike::where('post_id', $postId)
                ->where('user_id', $user->id)
                ->first();

            \Log::info("👍 Existing like check", [
                'existing_like' => $existingLike ? 'found' : 'not found',
                'like_id' => $existingLike ? $existingLike->id : null
            ]);

            if ($existingLike) {
                // If already liked, toggle it (unlike)
                $existingLike->delete();
                
                \Log::info("👍 Like toggled (removed)", [
                    'deleted_like_id' => $existingLike->id
                ]);

                // Get updated like count
                $likeCount = \App\Models\PostLike::where('post_id', $postId)->count();

                \Log::info("👍 Like toggle success (unlike)", [
                    'new_like_count' => $likeCount
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Post unliked successfully',
                    'likes_count' => $likeCount,
                    'is_liked' => false
                ]);
            }

            // Create the like
            $newLike = \App\Models\PostLike::create([
                'post_id' => $postId,
                'user_id' => $user->id
            ]);

            \Log::info("👍 Like created", [
                'like_id' => $newLike->id
            ]);

            // Send notification to post author
            try {
                $this->notificationService->sendPostLikeNotification($post, $user);
            } catch (\Exception $e) {
                \Log::error('Failed to send like notification', [
                    'post_id' => $postId,
                    'liker_id' => $user->id,
                    'error' => $e->getMessage()
                ]);
                // Don't fail the like operation if notification fails
            }

            // Get updated like count
            $likeCount = \App\Models\PostLike::where('post_id', $postId)->count();

            \Log::info("👍 Like post success", [
                'new_like_count' => $likeCount
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Post liked successfully',
                'likes_count' => $likeCount,
                'is_liked' => true
            ]);

        } catch (\Exception $e) {
            \Log::error('Error liking post', [
                'room_id' => $roomId,
                'post_id' => $postId,
                'user_id' => Auth::id(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to like post',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Unlike a post
     */
    public function unlikePost($roomId, $postId)
    {
        try {
            \Log::info("👎 Unlike post request", [
                'room_id' => $roomId,
                'post_id' => $postId,
                'user_id' => Auth::id()
            ]);

            $room = Room::findOrFail($roomId);
            $post = Post::where('room_id', $roomId)->findOrFail($postId);
            $user = Auth::user();

            // Check permissions based on post visibility
            if ($post->visibility === 'private') {
                // For private posts, user must be room member or owner
                $isMember = RoomMember::where('room_id', $roomId)
                    ->where('user_id', $user->id)
                    ->where('status', 'approved')
                    ->exists();

                if (!$isMember && $room->owner_id !== $user->id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'You must be a member of this room to unlike private posts'
                    ], 403);
                }
            }
            // For public posts, any authenticated user can unlike

            // Find and delete the like
            $like = \App\Models\PostLike::where('post_id', $postId)
                ->where('user_id', $user->id)
                ->first();

            \Log::info("👎 Like to delete check", [
                'like_found' => $like ? 'found' : 'not found',
                'like_id' => $like ? $like->id : null
            ]);

            if (!$like) {
                return response()->json([
                    'success' => false,
                    'message' => 'You have not liked this post'
                ], 400);
            }

            $like->delete();

            \Log::info("👎 Like deleted", [
                'deleted_like_id' => $like->id
            ]);

            // Get updated like count
            $likeCount = \App\Models\PostLike::where('post_id', $postId)->count();

            \Log::info("👎 Unlike post success", [
                'new_like_count' => $likeCount
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Post unliked successfully',
                'likes_count' => $likeCount,
                'is_liked' => false
            ]);

        } catch (\Exception $e) {
            \Log::error('Error unliking post', [
                'room_id' => $roomId,
                'post_id' => $postId,
                'user_id' => Auth::id(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to unlike post',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get post like information
     */
    public function getPostLikes($roomId, $postId)
    {
        try {
            $room = Room::findOrFail($roomId);
            $post = Post::where('room_id', $roomId)->findOrFail($postId);
            $user = Auth::user();

            // Check permissions based on post visibility
            if ($post->visibility === 'private') {
                // For private posts, user must be room member or owner
                $isMember = RoomMember::where('room_id', $roomId)
                    ->where('user_id', $user->id)
                    ->where('status', 'approved')
                    ->exists();

                if (!$isMember && $room->owner_id !== $user->id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'You must be a member of this room to view likes on private posts'
                    ], 403);
                }
            }
            // For public posts, any authenticated user can view likes

            $likeCount = \App\Models\PostLike::where('post_id', $postId)->count();
            $isLiked = \App\Models\PostLike::where('post_id', $postId)
                ->where('user_id', $user->id)
                ->exists();

            return response()->json([
                'success' => true,
                'likes_count' => $likeCount,
                'is_liked' => $isLiked
            ]);

        } catch (\Exception $e) {
            \Log::error('Error getting post likes', [
                'room_id' => $roomId,
                'post_id' => $postId,
                'user_id' => Auth::id(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to get post likes',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
