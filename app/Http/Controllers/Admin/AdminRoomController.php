<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Room;
use App\Models\User;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AdminRoomController extends Controller
{
    /**
     * Get all rooms with filtering, sorting, and pagination
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        try {
            $query = Room::with(['owner', 'members', 'posts'])
                ->withCount(['members as total_members', 'posts as total_posts']);

            // Search by name only
            if ($request->filled('search')) {
                $search = $request->get('search');
                $query->where('name', 'like', "%{$search}%");
            }

            // Filter by room type
            if ($request->filled('type') && $request->type !== 'all') {
                $query->where('type', $request->type);
            }

            // Filter by category (commercial vs non-commercial)
            if ($request->filled('category') && $request->category !== 'all_categories') {
                if ($request->category === 'commercial') {
                    $query->where('is_commercial', true);
                } else {
                    $query->where('is_commercial', false);
                }
            }

            // Sort by
            $sortBy = $request->get('sort_by', 'created_at');
            $sortOrder = $request->get('sort_order', 'desc');
            
            // Validate sort fields
            $allowedSortFields = ['created_at', 'name', 'type', 'total_members', 'total_posts', 'updated_at'];
            if (in_array($sortBy, $allowedSortFields)) {
                $query->orderBy($sortBy, $sortOrder);
            }

            // Pagination
            $perPage = $request->get('per_page', 15);
            $rooms = $query->paginate($perPage);

            // Transform the data to include additional statistics
            $rooms->getCollection()->transform(function ($room) {
                $lastActivity = $room->posts()
                    ->latest('created_at')
                    ->first();

                return [
                    'id' => $room->id,
                    'name' => $room->name,
                    'description' => $room->description,
                    'type' => $room->type,
                    'owner' => $room->owner ? $room->owner->name : 'Unknown',
                    'owner_id' => $room->owner_id,
                    'owner_email' => $room->owner ? $room->owner->email : null,
                    'members' => $room->total_members,
                    'posts' => $room->total_posts,
                    'category' => $room->is_commercial ? 'Commercial' : 'General',
                    'is_commercial' => $room->is_commercial,
                    'image' => $room->image,
                    'created_at' => $room->created_at,
                    'updated_at' => $room->updated_at,
                    'last_activity' => $lastActivity ? $lastActivity->created_at : $room->updated_at,
                    'is_active' => $room->updated_at->gt(Carbon::now()->subDays(30)), // Active if updated in last 30 days
                ];
            });

            // Get summary statistics
            $totalRooms = Room::count();
            $publicRooms = Room::where('type', 'public')->count();
            $privateRooms = Room::where('type', 'private')->count();
            $secureRooms = Room::where('type', 'secure')->count();
            $commercialRooms = Room::where('is_commercial', true)->count();
            $activeRooms = Room::where('updated_at', '>', Carbon::now()->subDays(30))->count();

            return response()->json([
                'success' => true,
                'data' => $rooms,
                'summary' => [
                    'total_rooms' => $totalRooms,
                    'public_rooms' => $publicRooms,
                    'private_rooms' => $privateRooms,
                    'secure_rooms' => $secureRooms,
                    'commercial_rooms' => $commercialRooms,
                    'active_rooms' => $activeRooms,
                    'showing' => $rooms->count(),
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch rooms: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a specific room by ID with detailed information
     * 
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(int $id)
    {
        try {
            $room = Room::with([
                'owner',
                'members' => function ($query) {
                    $query->with('user')->latest()->limit(10);
                },
                'posts' => function ($query) {
                    $query->with('author')->latest()->limit(5);
                },
                'products' => function ($query) {
                    $query->latest()->limit(5);
                }
            ])
            ->withCount(['members', 'posts', 'products'])
            ->find($id);

            if (!$room) {
                return response()->json([
                    'success' => false,
                    'message' => 'Room not found'
                ], 404);
            }

            // Get additional statistics
            $recentActivity = $room->posts()
                ->where('created_at', '>', Carbon::now()->subDays(7))
                ->count();

            $activeMembersCount = $room->members()
                ->whereHas('user', function ($query) {
                    $query->where('updated_at', '>', Carbon::now()->subDays(7));
                })
                ->count();

            $roomData = [
                'id' => $room->id,
                'name' => $room->name,
                'description' => $room->description,
                'type' => $room->type,
                'is_commercial' => $room->is_commercial,
                'image' => $room->image,
                'owner' => $room->owner,
                'created_at' => $room->created_at,
                'updated_at' => $room->updated_at,
                'members_count' => $room->members_count,
                'posts_count' => $room->posts_count,
                'products_count' => $room->products_count,
                'recent_activity_count' => $recentActivity,
                'active_members_count' => $activeMembersCount,
                'recent_members' => $room->members->map(function ($member) {
                    return [
                        'id' => $member->user->id,
                        'name' => $member->user->name,
                        'email' => $member->user->email,
                        'role' => $member->role,
                        'status' => $member->status,
                        'joined_at' => $member->created_at,
                    ];
                }),
                'recent_posts' => $room->posts->map(function ($post) {
                    return [
                        'id' => $post->id,
                        'title' => $post->title,
                        'author' => $post->author->name,
                        'created_at' => $post->created_at,
                    ];
                }),
            ];

            return response()->json([
                'success' => true,
                'data' => [
                    'room' => $roomData
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch room: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update room status or details
     * 
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, int $id)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255|unique:rooms,name,' . $id,
            'description' => 'sometimes|string|nullable',
            'type' => 'sometimes|in:public,private,secure',
            'is_commercial' => 'sometimes|boolean',
            'password' => 'sometimes|string|nullable'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $room = Room::find($id);
            
            if (!$room) {
                return response()->json([
                    'success' => false,
                    'message' => 'Room not found'
                ], 404);
            }

            $oldData = $room->toArray();
            $room->fill($request->only(['name', 'description', 'type', 'is_commercial', 'password']));
            $room->save();

            // Log the changes
            \Log::info("Admin updated room", [
                'admin_id' => auth()->id(),
                'room_id' => $room->id,
                'old_data' => $oldData,
                'new_data' => $room->toArray()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Room updated successfully',
                'data' => [
                    'room' => $room
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update room: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a room (soft delete with cleanup)
     * 
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy(Request $request, int $id)
    {
        $validator = Validator::make($request->all(), [
            'reason' => 'nullable|string|max:500'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $room = Room::with(['members', 'posts', 'products'])->find($id);
            
            if (!$room) {
                return response()->json([
                    'success' => false,
                    'message' => 'Room not found'
                ], 404);
            }

            $roomData = [
                'id' => $room->id,
                'name' => $room->name,
                'owner_id' => $room->owner_id,
                'members_count' => $room->members->count(),
                'posts_count' => $room->posts->count(),
                'products_count' => $room->products->count()
            ];

            // Delete the corresponding ChatRoom (if exists)
            $chatRoom = \App\Models\ChatRoom::find($id);
            if ($chatRoom) {
                $chatRoom->delete();
            }

            // Delete the room (this will cascade to members, posts, etc.)
            $room->delete();

            // Log the deletion
            \Log::warning("Admin deleted room", [
                'admin_id' => auth()->id(),
                'deleted_room' => $roomData,
                'reason' => $request->reason
            ]);

            // Log activity to activity logs
            $details = $request->reason ? 
                "Room '{$roomData['name']}' deleted with {$roomData['members_count']} members and {$roomData['posts_count']} posts. Reason: {$request->reason}" :
                "Room '{$roomData['name']}' deleted with {$roomData['members_count']} members and {$roomData['posts_count']} posts";

            ActivityLog::logActivity(
                auth()->id(),
                'Deleted Room',
                "Room: {$roomData['name']}",
                $details,
                'Room Management',
                'High',
                $request->ip()
            );

            return response()->json([
                'success' => true,
                'message' => 'Room has been deleted successfully',
                'data' => [
                    'deleted_room_id' => $id,
                    'deleted_at' => now(),
                    'affected_members' => $roomData['members_count'],
                    'affected_posts' => $roomData['posts_count']
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete room: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get room statistics for admin dashboard
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function getStatistics()
    {
        try {
            $stats = [
                'total_rooms' => Room::count(),
                'rooms_by_type' => [
                    'public' => Room::where('type', 'public')->count(),
                    'private' => Room::where('type', 'private')->count(),
                    'secure' => Room::where('type', 'secure')->count(),
                ],
                'commercial_stats' => [
                    'commercial' => Room::where('is_commercial', true)->count(),
                    'general' => Room::where('is_commercial', false)->count(),
                ],
                'activity_stats' => [
                    'active_rooms' => Room::where('updated_at', '>', Carbon::now()->subDays(30))->count(),
                    'inactive_rooms' => Room::where('updated_at', '<=', Carbon::now()->subDays(30))->count(),
                    'new_this_month' => Room::where('created_at', '>', Carbon::now()->subMonth())->count(),
                ],
                'top_rooms' => Room::withCount(['members', 'posts'])
                    ->orderBy('members_count', 'desc')
                    ->limit(5)
                    ->get()
                    ->map(function ($room) {
                        return [
                            'id' => $room->id,
                            'name' => $room->name,
                            'members_count' => $room->members_count,
                            'posts_count' => $room->posts_count,
                            'type' => $room->type
                        ];
                    })
            ];

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch statistics: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk operations on multiple rooms
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function bulkAction(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'action' => 'required|in:delete,toggle_commercial,set_type',
            'room_ids' => 'required|array|min:1',
            'room_ids.*' => 'integer|exists:rooms,id',
            'reason' => 'nullable|string|max:500',
            'type' => 'required_if:action,set_type|in:public,private,secure'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $roomIds = $request->room_ids;
            $action = $request->action;
            $results = [];
            $successCount = 0;
            $errorCount = 0;

            foreach ($roomIds as $roomId) {
                try {
                    $room = Room::find($roomId);
                    if (!$room) {
                        $results[] = ['room_id' => $roomId, 'status' => 'error', 'message' => 'Room not found'];
                        $errorCount++;
                        continue;
                    }

                    switch ($action) {
                        case 'delete':
                            $room->delete();
                            break;
                        case 'toggle_commercial':
                            $room->is_commercial = !$room->is_commercial;
                            $room->save();
                            break;
                        case 'set_type':
                            $room->type = $request->type;
                            $room->save();
                            break;
                    }

                    $results[] = ['room_id' => $roomId, 'status' => 'success', 'message' => 'Action completed'];
                    $successCount++;

                } catch (\Exception $e) {
                    $results[] = ['room_id' => $roomId, 'status' => 'error', 'message' => $e->getMessage()];
                    $errorCount++;
                }
            }

            // Log bulk action
            \Log::info("Admin performed bulk action on rooms", [
                'admin_id' => auth()->id(),
                'action' => $action,
                'room_ids' => $roomIds,
                'success_count' => $successCount,
                'error_count' => $errorCount,
                'reason' => $request->reason
            ]);

            return response()->json([
                'success' => true,
                'message' => "Bulk action completed. {$successCount} successful, {$errorCount} failed.",
                'data' => [
                    'action' => $action,
                    'success_count' => $successCount,
                    'error_count' => $errorCount,
                    'results' => $results
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to perform bulk action: ' . $e->getMessage()
            ], 500);
        }
    }
} 