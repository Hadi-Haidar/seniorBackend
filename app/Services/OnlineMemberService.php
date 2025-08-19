<?php

namespace App\Services;

use App\Models\ChatRoom;
use App\Models\User;
use App\Models\RoomOnlineMember;
use App\Events\UserOnlineStatusChanged;
use Illuminate\Support\Facades\Log;

class OnlineMemberService
{
    /**
     * Mark user as online in a room and broadcast the change
     */
    public function markUserOnline(int $roomId, int $userId): array
    {
        try {
            $room = ChatRoom::find($roomId);
            $user = User::find($userId);

            if (!$room || !$user) {
                return ['success' => false, 'message' => 'Room or user not found'];
            }

            // Check if user is a participant
            if (!$room->hasParticipant($userId)) {
                return ['success' => false, 'message' => 'User is not a participant of this room'];
            }

            // Mark user as online
            RoomOnlineMember::markUserOnline($roomId, $userId);

            // Get updated list of online members
            $onlineMembers = $this->getOnlineMembersForBroadcast($roomId);

            // Broadcast the status change
            broadcast(new UserOnlineStatusChanged($user, $roomId, true, $onlineMembers));

            return [
                'success' => true,
                'online_members' => $onlineMembers,
                'message' => 'User marked as online'
            ];
        } catch (\Exception $e) {
            Log::error("Error marking user online: " . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to mark user as online'];
        }
    }

    /**
     * Mark user as offline in a room and broadcast the change
     */
    public function markUserOffline(int $roomId, int $userId): array
    {
        try {
            $room = ChatRoom::find($roomId);
            $user = User::find($userId);

            if (!$room || !$user) {
                return ['success' => false, 'message' => 'Room or user not found'];
            }

            // Mark user as offline
            RoomOnlineMember::markUserOffline($roomId, $userId);

            // Get updated list of online members
            $onlineMembers = $this->getOnlineMembersForBroadcast($roomId);

            // Broadcast the status change
            broadcast(new UserOnlineStatusChanged($user, $roomId, false, $onlineMembers));

            return [
                'success' => true,
                'online_members' => $onlineMembers,
                'message' => 'User marked as offline'
            ];
        } catch (\Exception $e) {
            Log::error("Error marking user offline: " . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to mark user as offline'];
        }
    }

    /**
     * Update user's last seen timestamp in a room
     */
    public function updateUserActivity(int $roomId, int $userId): array
    {
        try {
            $room = ChatRoom::find($roomId);

            if (!$room) {
                return ['success' => false, 'message' => 'Room not found'];
            }

            // Check if user is a participant
            if (!$room->hasParticipant($userId)) {
                return ['success' => false, 'message' => 'User is not a participant of this room'];
            }

            // Update last seen timestamp (this will keep them online)
            RoomOnlineMember::markUserOnline($roomId, $userId);

            return ['success' => true, 'message' => 'User activity updated'];
        } catch (\Exception $e) {
            Log::error("Error updating user activity: " . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to update user activity'];
        }
    }

    /**
     * Get online members for a room
     */
    public function getOnlineMembers(int $roomId): array
    {
        try {
            $onlineMembers = RoomOnlineMember::getOnlineMembers($roomId);
            
            return [
                'success' => true,
                'online_members' => $this->formatOnlineMembersForResponse($onlineMembers)
            ];
        } catch (\Exception $e) {
            Log::error("Error getting online members: " . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to get online members'];
        }
    }

    /**
     * Clean up stale online members
     */
    public function cleanupStaleMembers(): int
    {
        return RoomOnlineMember::cleanupStaleMembers();
    }

    /**
     * Format online members for API response
     */
    private function formatOnlineMembersForResponse($onlineMembers): array
    {
        return $onlineMembers->map(function ($onlineMember) {
            return [
                'id' => $onlineMember->user->id,
                'name' => $onlineMember->user->name,
                'avatar' => $onlineMember->user->avatar,
                'email' => $onlineMember->user->email,
                'last_seen' => $onlineMember->last_seen->toISOString(),
            ];
        })->toArray();
    }

    /**
     * Get online members formatted for broadcasting
     */
    private function getOnlineMembersForBroadcast(int $roomId): array
    {
        $onlineMembers = RoomOnlineMember::getOnlineMembers($roomId);
        return $this->formatOnlineMembersForResponse($onlineMembers);
    }
} 