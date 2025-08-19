<?php

namespace App\Http\Controllers;

use App\Models\ChatRoom;
use App\Services\OnlineMemberService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\JsonResponse;
use App\Models\RoomOnlineMember;

class OnlineMemberController extends Controller
{
    protected $onlineMemberService;

    public function __construct(OnlineMemberService $onlineMemberService)
    {
        $this->onlineMemberService = $onlineMemberService;
    }

    /**
     * Get online members for a room
     */
    public function getOnlineMembers(ChatRoom $room): JsonResponse
    {
        // Check if user is participant
        if (!$room->hasParticipant(Auth::id())) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $result = $this->onlineMemberService->getOnlineMembers($room->id);

        if (!$result['success']) {
            return response()->json(['error' => $result['message']], 500);
        }

        return response()->json([
            'online_members' => $result['online_members'],
            'count' => count($result['online_members'])
        ]);
    }

    /**
     * Mark user as online in a room
     */
    public function markOnline(ChatRoom $room): JsonResponse
    {
        $result = $this->onlineMemberService->markUserOnline($room->id, Auth::id());

        if (!$result['success']) {
            return response()->json(['error' => $result['message']], 
                $result['message'] === 'User is not a participant of this room' ? 403 : 500
            );
        }

        return response()->json([
            'message' => 'Marked as online',
            'online_members' => $result['online_members']
        ]);
    }

    /**
     * Mark user as offline in a room
     */
    public function markOffline(ChatRoom $room): JsonResponse
    {
        $result = $this->onlineMemberService->markUserOffline($room->id, Auth::id());

        if (!$result['success']) {
            return response()->json(['error' => $result['message']], 500);
        }

        return response()->json([
            'message' => 'Marked as offline',
            'online_members' => $result['online_members']
        ]);
    }

    /**
     * Update user activity (heartbeat)
     */
    public function updateActivity(ChatRoom $room): JsonResponse
    {
        $result = $this->onlineMemberService->updateUserActivity($room->id, Auth::id());

        if (!$result['success']) {
            return response()->json(['error' => $result['message']], 
                $result['message'] === 'User is not a participant of this room' ? 403 : 500
            );
        }

        return response()->json(['message' => 'Activity updated']);
    }


} 