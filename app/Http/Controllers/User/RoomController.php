<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\User;
use App\Models\ChatRoom;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\RateLimiter;
use App\Models\CoinTransaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use App\Models\UserRoomUsage;
use App\Services\RoomLimitService;
use App\Services\NotificationService;

class RoomController extends Controller
{
    protected $roomLimitService;
    protected $notificationService;

    public function __construct(RoomLimitService $roomLimitService, NotificationService $notificationService)
    {
        $this->roomLimitService = $roomLimitService;
        $this->notificationService = $notificationService;
    }
    /**
     * Create a new room
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        \Log::info('Room creation started', [
            'user_id' => Auth::id(),
            'request_data' => $request->all()
        ]);
        
        $user = Auth::user();
        
        // Check room creation limits using the new service
        try {
            $canCreate = $this->roomLimitService->canCreateRoom($user);
            
            // Debug log
            \Log::info('Room creation check', [
                'user_id' => $user->id,
                'user_coins' => $user->coins,
                'can_create' => $canCreate,
                'subscription_level' => $user->getEffectiveSubscriptionLevel()
            ]);
            
            if (!$canCreate['can_create']) {
                $message = $canCreate['insufficient_coins'] 
                    ? "Insufficient coins to create additional room. You need {$canCreate['additional_cost']} coins but have {$canCreate['user_coins']} coins."
                    : "Cannot create room due to limits.";
                    
                return response()->json([
                    'success' => false,
                    'message' => $message,
                    'usage_info' => $this->roomLimitService->getRoomUsageSummary($user)
                ], 403);
            }
        } catch (\Exception $e) {
            \Log::error('Room creation error', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'usage_info' => $this->roomLimitService->getRoomUsageSummary($user)
            ], 400);
        }
        
        // Validate request data
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                'unique:rooms,name'
            ],
            'description' => 'nullable|string',
            'type' => ['required', Rule::in(['public', 'private', 'secure'])],
            'password' => [
                Rule::requiredIf(function () use ($request) {
                    return $request->type === 'secure';
                }),
                'nullable',
                'string',
                'min:6'
            ],
            'is_commercial' => 'nullable|in:0,1,true,false',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
        ], [
            'name.unique' => 'The room name has already been taken.',
            'name.required' => 'Room name is required.',
            'name.max' => 'Room name must not exceed 255 characters.',
            'password.required' => 'Password is required for secure rooms.',
            'password.min' => 'Password must be at least 6 characters.',
        ]);
        
        // Convert is_commercial to boolean
        $validated['is_commercial'] = filter_var($validated['is_commercial'] ?? false, FILTER_VALIDATE_BOOLEAN);
        
        // Check if user is trying to create a commercial room
        if ($validated['is_commercial'] && Auth::user()->subscription_level !== 'gold') {
            return response()->json([
                'success' => false,
                'message' => 'Only Gold subscribers can create commercial rooms. Please upgrade your subscription to Gold level.'
            ], 403);
        }
        
        // Hash password if provided
        if (isset($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        }
        
        // Handle image upload
        if ($request->hasFile('image')) {
            Log::info('Image upload debug', [
                'has_file' => $request->hasFile('image'),
                'file_errors' => $request->file('image') ? null : 'No file detected',
                'all_data' => $request->all()
            ]);
            $path = $request->file('image')->store('room_images', 'public');
            $validated['image'] = $path;
        }
        
        try {
            DB::beginTransaction();
            
            // Create the room
            $validated['owner_id'] = $user->id;
            $room = Room::create($validated);
            
            // Create corresponding ChatRoom for live chat with the same ID
            $chatRoom = ChatRoom::create([
                'id' => $room->id,
                'name' => $validated['name'] . ' - Chat',
                'description' => 'Live chat for ' . $validated['name']
            ]);
            
            // Add creator as participant in the chat room
            $chatRoom->addParticipant($user->id);
            
            // Add creator as member and moderator
            RoomMember::create([
                'room_id' => $room->id,
                'user_id' => $user->id,
                'role' => 'moderator',
                'status' => 'approved'
            ]);
            
            // Process room creation using the new service (this handles coin deduction and usage tracking)
            $usageResult = $this->roomLimitService->processRoomCreation($user);
            
            DB::commit();
            
            // Add image URL to response
            if (isset($room->image)) {
                $room->image_url = asset('storage/' . $room->image);
            }
            
            // Prepare response message
            $message = $usageResult['cost_deducted'] 
                ? "Room created successfully! {$usageResult['coins_spent']} coins deducted. Remaining balance: {$usageResult['remaining_coins']} coins."
                : 'Room created successfully (free)';
            
            $responseData = [
                'success' => true,
                'message' => $message,
                'room' => $room,
                'usage_info' => $this->roomLimitService->getRoomUsageSummary($user),
                'cost_info' => [
                    'was_free' => !$usageResult['cost_deducted'],
                    'coins_spent' => $usageResult['coins_spent'],
                    'remaining_coins' => $usageResult['remaining_coins']
                ]
            ];
            
            return response()->json($responseData, 201);
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to create room: ' . $e->getMessage(),
                'usage_info' => $this->roomLimitService->getRoomUsageSummary($user)
            ], 500);
        }
    }

    /**
     * Get room usage summary for the authenticated user
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getRoomUsageSummary()
    {
        $user = Auth::user();
        $usage = $this->roomLimitService->getRoomUsageSummary($user);
        
        return response()->json([
            'success' => true,
            'usage' => $usage
        ]);
    }
    
    /**
     * Get list of rooms
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        // Log the request parameters for debugging
        Log::info('Rooms index request', [
            'user_id' => Auth::id(),
            'params' => $request->all()
        ]);

        // Basic filtering
        $query = Room::query();
        
        // Filter by type if provided
        if ($request->has('type')) {
            $query->where('type', $request->type);
        }
        
        // Filter by commercial status if provided
        if ($request->has('is_commercial')) {
            $query->where('is_commercial', $request->is_commercial);
        }
        
        // Search by name if provided
        if ($request->has('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }
        
        // Get only rooms where user is a member OR owner
        if ($request->has('my_rooms') && $request->my_rooms) {
            $query->where(function($q) {
                $q->whereHas('members', function($memberQuery) {
                    $memberQuery->where('user_id', Auth::id())
                              ->where('status', 'approved');
                })->orWhere('owner_id', Auth::id());
            });
        }
        
        // Get owned rooms
        if ($request->has('owned') && $request->owned) {
            $query->where('owner_id', Auth::id());
        }
        
        // Add relationship data
        $query->with(['owner:id,name,avatar']);
        $query->withCount(['members' => function($q) {
            $q->where('status', 'approved');
        }]);
        
        // Get paginated results
        $rooms = $query->orderBy('created_at', 'desc')
                      ->paginate($request->per_page ?? 15);
        
        // Get all room IDs for bulk membership check
        $roomIds = $rooms->getCollection()->pluck('id')->toArray();
        
        // Bulk check membership for all rooms in single query
        $memberships = [];
        if (!empty($roomIds)) {
            $userMemberships = RoomMember::where('user_id', Auth::id())
                ->whereIn('room_id', $roomIds)
                ->get()
                ->keyBy('room_id');
            
            foreach ($roomIds as $roomId) {
                $membership = $userMemberships->get($roomId);
                $memberships[$roomId] = [
                    'is_member' => $membership && $membership->status === 'approved',
                    'is_owner' => false, // Will be set below
                    'membership_status' => $membership ? $membership->status : null,
                    'role' => $membership ? $membership->role : null,
                ];
            }
        }
        
        // Transform results and add membership + image URLs
        $rooms->getCollection()->transform(function ($room) use ($memberships) {
            // Add image URL
            if ($room->image) {
                $room->image_url = asset('storage/' . $room->image);
            }
            
            // Add membership information
            $membership = $memberships[$room->id] ?? [
                'is_member' => false,
                'is_owner' => false,
                'membership_status' => null,
                'role' => null,
            ];
            
            // Check if user is the owner
            $isOwner = $room->owner_id === Auth::id();
            $membership['is_owner'] = $isOwner;
            $membership['is_member'] = $membership['is_member'] || $isOwner;
            
            if ($isOwner) {
                $membership['role'] = 'owner';
            }
            
            // Add membership data to room object
            $room->membership = $membership;
            
            return $room;
        });
        
        // Log the results for debugging
        Log::info('Rooms index results', [
            'total_rooms' => $rooms->total(),
            'current_page' => $rooms->currentPage(),
            'rooms_on_page' => $rooms->count()
        ]);
        
        return response()->json([
            'success' => true,
            'rooms' => $rooms
        ]);
    }
    
    /**
     * Join a room
     * 
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function join(Request $request, $id)
    {
        $user = Auth::user();
        $room = Room::findOrFail($id);
        
        // Check if user is already a member
        $existingMembership = RoomMember::where('room_id', $room->id)
            ->where('user_id', $user->id)
            ->first();
            
        if ($existingMembership) {
            if ($existingMembership->status === 'approved') {
                return response()->json([
                    'success' => false,
                    'message' => 'You are already a member of this room'
                ], 400);
            } elseif ($existingMembership->status === 'pending') {
                return response()->json([
                    'success' => false,
                    'message' => 'Your request to join this room is pending approval'
                ], 400);
            } elseif ($existingMembership->status === 'rejected') {
                return response()->json([
                    'success' => false,
                    'message' => 'Your request to join this room was rejected'
                ], 403);
            } elseif ($existingMembership->status === 'banned') {
                return response()->json([
                    'success' => false,
                    'message' => 'You have been permanently banned from this room and cannot rejoin'
                ], 403);
            } elseif ($existingMembership->status === 'removed') {
                // Check if 24 hours have passed since removal
                $removedAt = Carbon::parse($existingMembership->removed_at);
                $now = Carbon::now();
                $hoursSinceRemoval = $removedAt->diffInHours($now);
                
                if ($hoursSinceRemoval < 24) {
                    $hoursRemaining = 24 - $hoursSinceRemoval;
                    return response()->json([
                        'success' => false,
                        'message' => "You have been temporarily removed from this room. You can rejoin after {$hoursRemaining} hours.",
                        'rejoin_at' => $removedAt->addHours(24)->toDateTimeString()
                    ], 403);
                }
                
                // If 24 hours have passed, update status to allow rejoining
                $existingMembership->status = 'approved';
                $existingMembership->removed_at = null;
                $existingMembership->save();
                
                return response()->json([
                    'success' => true,
                    'message' => 'You have rejoined the room successfully'
                ]);
            }
        }
        
        // Handle different room types
        try {
            switch ($room->type) {
                case 'public':
                    // Auto-approve for public rooms
                    RoomMember::create([
                        'room_id' => $room->id,
                        'user_id' => $user->id,
                        'role' => 'member',
                        'status' => 'approved'
                    ]);
                    
                    // Add user to the corresponding chat room
                    $chatRoom = $room->chatRoom();
                    $chatRoom->addParticipant($user->id);
                    
                    return response()->json([
                        'success' => true,
                        'message' => 'You have joined the room successfully'
                    ]);
                    
                case 'secure':
                    // Validate password
                    $request->validate(['password' => 'required|string']);
                    
                    if (!Hash::check($request->password, $room->password)) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Incorrect password'
                        ], 422);
                    }
                    
                    // Password is correct, approve membership
                    RoomMember::create([
                        'room_id' => $room->id,
                        'user_id' => $user->id,
                        'role' => 'member',
                        'status' => 'approved'
                    ]);
                    
                    // Add user to the corresponding chat room
                    $chatRoom = $room->chatRoom();
                    $chatRoom->addParticipant($user->id);
                    
                    return response()->json([
                        'success' => true,
                        'message' => 'You have joined the room successfully'
                    ]);
                    
                case 'private':
                    // Create pending membership
                    $membership = RoomMember::create([
                        'room_id' => $room->id,
                        'user_id' => $user->id,
                        'role' => 'member',
                        'status' => 'pending'
                    ]);

                    // Send notifications to room owner and moderators
                    try {
                        $this->notificationService->sendRoomJoinRequestNotifications($room, $user);
                    } catch (\Exception $e) {
                        \Log::error('Failed to send room join request notifications', [
                            'room_id' => $room->id,
                            'requester_id' => $user->id,
                            'error' => $e->getMessage()
                        ]);
                        // Don't fail the join request if notification fails
                    }
                    
                    return response()->json([
                        'success' => true,
                        'message' => 'Your request to join has been sent to the room owner'
                    ]);
                    
                default:
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid room type'
                    ], 400);
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to join room',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get room details
     * 
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $room = Room::find($id);
        if (!$room) {
            return response()->json(['success' => false, 'message' => 'Room not found'], 404);
        }
        
        // Explicitly check for ownership first
        $isOwner = $room->owner_id === Auth::id();
        
        $room = Room::with([
            'owner:id,name,avatar',
            'members' => function($query) {
                $query->where('status', 'approved')
                      ->with('user:id,name,avatar');
            }
        ])->findOrFail($id);
        
        // Check if user is a member OR owner for private rooms
        if ($room->type === 'private') {
            $isMember = RoomMember::where('room_id', $room->id)
                ->where('user_id', Auth::id())
                ->where('status', 'approved')
                ->exists();
                
            if (!$isMember && !$isOwner) {
                return response()->json([
                    'success' => false,
                    'message' => 'You must be a member to view this private room'
                ], 403);
            }
        }
        
        // Add image URL if exists
        if ($room->image) {
            $room->image_url = asset('storage/' . $room->image);
        }
        
        return response()->json([
            'success' => true,
            'room' => $room
        ]);
    }
    
    /**
     * Update room details
     * 
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $room = Room::findOrFail($id);
        
        // Check if user is authorized to update the room
        if ($room->owner_id !== Auth::id()) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to update this room'
            ], 403);
        }
        
        // Add debugging to see what data is being received
        Log::info('Room update request received', [
            'room_id' => $id,
            'user_id' => Auth::id(),
            'request_all' => $request->all(),
            'request_input_type' => $request->input('type'),
            'current_room_type' => $room->type
        ]);
        
        // Validate request data
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255|unique:rooms,name,' . $id,
            'description' => 'sometimes|nullable|string',
            'type' => ['sometimes', Rule::in(['public', 'private', 'secure'])],
            'password' => [
                'sometimes',
                'nullable',
                'string',
                'min:6',
                Rule::requiredIf(function () use ($request, $room) {
                    return ($request->type ?? $room->type) === 'secure';
                }),
            ],
            'is_commercial' => 'nullable|in:0,1,true,false',
            'image' => 'sometimes|nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
        ], [
            'name.unique' => 'The room name has already been taken.',
            'name.max' => 'Room name must not exceed 255 characters.',
            'password.required' => 'Password is required for secure rooms.',
            'password.min' => 'Password must be at least 6 characters.',
        ]);
        
        // Log what passed validation
        Log::info('Room update validation passed', [
            'validated_data' => $validated
        ]);
        
        // Convert is_commercial to boolean
        $validated['is_commercial'] = filter_var($validated['is_commercial'] ?? false, FILTER_VALIDATE_BOOLEAN);
        
        // Check if user is trying to create a commercial room
        if ($validated['is_commercial'] && Auth::user()->subscription_level !== 'gold') {
            return response()->json([
                'success' => false,
                'message' => 'Only Gold subscribers can create commercial rooms. Please upgrade your subscription to Gold level.'
            ], 403);
        }
        
        // Hash password if provided
        if (isset($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        }
        
        // Handle image upload
        if ($request->hasFile('image')) {
            // Delete old image if exists
            if ($room->image) {
                \Storage::disk('public')->delete($room->image);
            }
            
            $path = $request->file('image')->store('room_images', 'public');
            $validated['image'] = $path;
        }
        
        // Handle image removal
        if ($request->has('remove_image') && $request->remove_image && $room->image) {
            \Storage::disk('public')->delete($room->image);
            $validated['image'] = null;
        }
        
        // Log the data that will be used for update
        Log::info('About to update room with data', [
            'room_id' => $id,
            'update_data' => $validated,
            'room_before_update' => $room->toArray()
        ]);
        
        // Update the room
        $room->update($validated);
        
        // Log the room after update
        $room->refresh();
        Log::info('Room after update', [
            'room_id' => $id,
            'room_after_update' => $room->toArray()
        ]);
        
        // Add image URL to response
        if ($room->image) {
            $room->image_url = asset('storage/' . $room->image);
        }
        
        return response()->json([
            'success' => true,
            'message' => 'Room updated successfully',
            'room' => $room
        ]);
    }
    
    /**
     * Delete a room
     * 
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $room = Room::findOrFail($id);
        
        // Check if user is authorized to delete the room
        if ($room->owner_id !== Auth::id()) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to delete this room'
            ], 403);
        }
        
        // Delete all memberships and the room
        try {
            DB::beginTransaction();
            
            // Delete all memberships
            RoomMember::where('room_id', $id)->delete();
            
            // Delete the corresponding ChatRoom (if exists)
            $chatRoom = \App\Models\ChatRoom::find($id);
            if ($chatRoom) {
                $chatRoom->delete();
            }
            
            // Delete the room
            $room->delete();
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => 'Room deleted successfully'
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete room',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Approve a pending room membership (for room owners/moderators)
     * 
     * @param Request $request
     * @param int $roomId
     * @param int $userId
     * @return \Illuminate\Http\JsonResponse
     */
    public function approveMember(Request $request, $roomId, $userId)
    {
        $room = Room::findOrFail($roomId);
        
        // Check if user is authorized to approve
        $userMembership = RoomMember::where('room_id', $roomId)
            ->where('user_id', Auth::id())
            ->first();
            
        if (!$userMembership || 
            ($userMembership->role !== 'moderator' && $room->owner_id !== Auth::id())) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to approve members'
            ], 403);
        }
        
        // Find the pending membership
        $membership = RoomMember::where('room_id', $roomId)
            ->where('user_id', $userId)
            ->where('status', 'pending')
            ->first();
            
        if (!$membership) {
            return response()->json([
                'success' => false,
                'message' => 'No pending membership request found'
            ], 404);
        }
        
        // Approve the membership
        $membership->status = 'approved';
        $membership->save();
        
        // Add user to the corresponding chat room
        $chatRoom = $room->chatRoom();
        $chatRoom->addParticipant($userId);
        
        return response()->json([
            'success' => true,
            'message' => 'Membership approved successfully'
        ]);
    }
    
    /**
     * Reject a pending room membership
     * 
     * @param Request $request
     * @param int $roomId
     * @param int $userId
     * @return \Illuminate\Http\JsonResponse
     */
    public function rejectMember(Request $request, $roomId, $userId)
    {
        $room = Room::findOrFail($roomId);
        
        // Check if user is authorized to reject
        $userMembership = RoomMember::where('room_id', $roomId)
            ->where('user_id', Auth::id())
            ->first();
            
        if (!$userMembership || 
            ($userMembership->role !== 'moderator' && $room->owner_id !== Auth::id())) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to reject members'
            ], 403);
        }
        
        // Find the pending membership
        $membership = RoomMember::where('room_id', $roomId)
            ->where('user_id', $userId)
            ->where('status', 'pending')
            ->first();
            
        if (!$membership) {
            return response()->json([
                'success' => false,
                'message' => 'No pending membership request found'
            ], 404);
        }
        
        // Reject the membership
        $membership->status = 'rejected';
        $membership->save();
        
        return response()->json([
            'success' => true,
            'message' => 'Membership rejected successfully'
        ]);
    }
    
    /**
     * Get pending membership requests for a room
     * 
     * @param int $roomId
     * @return \Illuminate\Http\JsonResponse
     */
    public function pendingRequests($roomId)
    {
        $room = Room::findOrFail($roomId);
        
        // Check if user is authorized to view pending requests
        $userMembership = RoomMember::where('room_id', $roomId)
            ->where('user_id', Auth::id())
            ->first();
            
        if (!$userMembership || 
            ($userMembership->role !== 'moderator' && $room->owner_id !== Auth::id())) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to view pending requests'
            ], 403);
        }
        
        // Get pending memberships with user details
        $pendingRequests = RoomMember::where('room_id', $roomId)
            ->where('status', 'pending')
            ->with('user:id,name,avatar')
            ->get();
        
        return response()->json([
            'success' => true,
            'pending_requests' => $pendingRequests
        ]);
    }
    
    /**
     * Leave a room
     * 
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function leave($id)
    {
        $room = Room::findOrFail($id);
        
        // Cannot leave if you're the owner
        if ($room->owner_id === Auth::id()) {
            return response()->json([
                'success' => false,
                'message' => 'Room owners cannot leave. Transfer ownership or delete the room.'
            ], 400);
        }
        
        // Delete membership (actual deletion, not banning, since this is voluntary)
        $deleted = RoomMember::where('room_id', $id)
            ->where('user_id', Auth::id())
            ->delete();
            
        if (!$deleted) {
            return response()->json([
                'success' => false,
                'message' => 'You are not a member of this room'
            ], 400);
        }
        
        return response()->json([
            'success' => true,
            'message' => 'You have left the room'
        ]);
    }
    
    /**
     * Helper method to deduct coins from user
     * 
     * @param User $user
     * @param int $amount
     * @return bool
     */
    private function deductCoins(User $user, int $amount, string $subscriptionLevel): bool
    {
        // Get the user's coins
        $userCoins = $this->getUserCoins($user);
        
        // Check if user has enough coins
        if ($userCoins < $amount) {
            return false;
        }
        
        // Create transaction to spend coins
        try {
            DB::beginTransaction();
            
            // Determine the action description based on subscription level and current usage
            $roomsToday = Room::where('owner_id', $user->id)
                ->whereDate('created_at', now()->toDateString())
                ->count();
            
            $actionDescription = 'create_additional_room';
            $notes = 'Payment for creating additional room';
            
            if ($subscriptionLevel === 'bronze') {
                $roomsThisMonth = Room::where('owner_id', $user->id)
                    ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
                    ->count();
                $notes = "Payment for additional room (exceeded monthly limit of 10 rooms)";
            } else {
                $additionalRoomsToday = max(0, $roomsToday - 3);
                $roomNumber = $additionalRoomsToday + 1;
                $notes = "Payment for additional room #{$roomNumber} today (escalating cost: {$amount} coins)";
            }
            
            // Create coin transaction
            CoinTransaction::create([
                'user_id' => $user->id,
                'direction' => 'out',
                'amount' => $amount,
                'source_type' => 'spend',
                'action' => $actionDescription,
                'notes' => $notes
            ]);
            
            DB::commit();
            return true;
            
        } catch (\Exception $e) {
            DB::rollBack();
            return false;
        }
    }
    
    /**
     * Get user's current coin balance
     * 
     * @param User $user
     * @return int
     */
    private function getUserCoins(User $user): int
    {
        // Calculate user's current coin balance from transactions
        $inCoins = CoinTransaction::where('user_id', $user->id)
                    ->where('direction', 'in')
                    ->sum('amount');
                    
        $outCoins = CoinTransaction::where('user_id', $user->id)
                    ->where('direction', 'out')
                    ->sum('amount');
                    
        return $inCoins - $outCoins;
    }
    
    /**
     * Remove a member from the room
     * 
     * @param Request $request
     * @param int $roomId
     * @param int $userId
     * @return \Illuminate\Http\JsonResponse
     */
    public function removeMember(Request $request, $roomId, $userId)
    {
        $room = Room::findOrFail($roomId);
        $currentUser = Auth::user();
        $permanent = $request->input('permanent', false);

        // Check if the current user is authorized to remove members
        $userMembership = RoomMember::where('room_id', $roomId)
            ->where('user_id', $currentUser->id)
            ->first();
            
        if (!$userMembership || 
            ($userMembership->role !== 'moderator' && $room->owner_id !== $currentUser->id)) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to remove members'
            ], 403);
        }

        // Cannot remove the room owner
        if ($userId == $room->owner_id) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot remove the room owner'
            ], 403);
        }

        // Regular moderators cannot remove other moderators
        $targetMembership = RoomMember::where('room_id', $roomId)
            ->where('user_id', $userId)
            ->first();

        if (!$targetMembership) {
            return response()->json([
                'success' => false,
                'message' => 'User is not a member of this room'
            ], 404);
        }

        // Only the owner can remove moderators
        if ($targetMembership->role === 'moderator' && $room->owner_id !== $currentUser->id) {
            return response()->json([
                'success' => false,
                'message' => 'Only the room owner can remove moderators'
            ], 403);
        }

        // Set appropriate status based on permanent flag
        if ($permanent) {
            // Permanent ban
            $targetMembership->status = 'banned';
            $message = 'Member permanently banned from this room';
        } else {
            // Temporary removal (24 hours)
            $targetMembership->status = 'removed';
            $targetMembership->removed_at = now();
            $message = 'Member temporarily removed from this room (24 hour restriction)';
        }
        
        $targetMembership->save();

        return response()->json([
            'success' => true,
            'message' => $message
        ]);
    }

    /**
     * Promote a member to moderator (only room owner can do this)
     * 
     * @param Request $request
     * @param int $roomId
     * @param int $userId
     * @return \Illuminate\Http\JsonResponse
     */
    public function promoteToModerator(Request $request, $roomId, $userId)
    {
        $room = Room::findOrFail($roomId);
        $currentUser = Auth::user();

        // Check if the current user is the room owner
        if ($room->owner_id !== $currentUser->id) {
            return response()->json([
                'success' => false,
                'message' => 'Only the room owner can promote members to moderators'
            ], 403);
        }

        // Cannot promote the room owner (they're already the highest role)
        if ($userId == $room->owner_id) {
            return response()->json([
                'success' => false,
                'message' => 'Room owner already has the highest privileges'
            ], 400);
        }

        // Find the target membership
        $targetMembership = RoomMember::where('room_id', $roomId)
            ->where('user_id', $userId)
            ->where('status', 'approved')
            ->first();

        if (!$targetMembership) {
            return response()->json([
                'success' => false,
                'message' => 'User is not an approved member of this room'
            ], 404);
        }

        // Check if user is already a moderator
        if ($targetMembership->role === 'moderator') {
            return response()->json([
                'success' => false,
                'message' => 'User is already a moderator'
            ], 400);
        }

        // Promote to moderator
        $targetMembership->role = 'moderator';
        $targetMembership->save();

        return response()->json([
            'success' => true,
            'message' => 'Member promoted to moderator successfully',
            'member' => [
                'user_id' => $targetMembership->user_id,
                'role' => $targetMembership->role,
                'user' => $targetMembership->user
            ]
        ]);
    }

    /**
     * Demote a moderator to member (only room owner can do this)
     * 
     * @param Request $request
     * @param int $roomId
     * @param int $userId
     * @return \Illuminate\Http\JsonResponse
     */
    public function demoteToMember(Request $request, $roomId, $userId)
    {
        $room = Room::findOrFail($roomId);
        $currentUser = Auth::user();

        // Check if the current user is the room owner
        if ($room->owner_id !== $currentUser->id) {
            return response()->json([
                'success' => false,
                'message' => 'Only the room owner can demote moderators to members'
            ], 403);
        }

        // Cannot demote the room owner
        if ($userId == $room->owner_id) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot demote the room owner'
            ], 400);
        }

        // Find the target membership
        $targetMembership = RoomMember::where('room_id', $roomId)
            ->where('user_id', $userId)
            ->where('status', 'approved')
            ->first();

        if (!$targetMembership) {
            return response()->json([
                'success' => false,
                'message' => 'User is not an approved member of this room'
            ], 404);
        }

        // Check if user is actually a moderator
        if ($targetMembership->role !== 'moderator') {
            return response()->json([
                'success' => false,
                'message' => 'User is not a moderator'
            ], 400);
        }

        // Demote to member
        $targetMembership->role = 'member';
        $targetMembership->save();

        return response()->json([
            'success' => true,
            'message' => 'Moderator demoted to member successfully',
            'member' => [
                'user_id' => $targetMembership->user_id,
                'role' => $targetMembership->role,
                'user' => $targetMembership->user
            ]
        ]);
    }

    /**
     * Get room members with their roles (for room management)
     * 
     * @param int $roomId
     * @return \Illuminate\Http\JsonResponse
     */
    public function getRoomMembers($roomId)
    {
        $room = Room::findOrFail($roomId);
        $currentUser = Auth::user();

        // Check if user is authorized to view members (any approved member or owner)
        $userMembership = RoomMember::where('room_id', $roomId)
            ->where('user_id', $currentUser->id)
            ->where('status', 'approved')
            ->first();
            
        $isOwner = $room->owner_id === $currentUser->id;
        
        if (!$userMembership && !$isOwner) {
            return response()->json([
                'success' => false,
                'message' => 'You must be a member of this room to view the members list'
            ], 403);
        }

        // Get all approved members with user details
        $members = RoomMember::where('room_id', $roomId)
            ->where('status', 'approved')
            ->with('user:id,name,avatar,email')
            ->orderBy('role', 'desc') // Show moderators first, then members
            ->orderBy('created_at', 'asc')
            ->get();

        // Add owner information
        $owner = User::select('id', 'name', 'avatar', 'email')->find($room->owner_id);

        return response()->json([
            'success' => true,
            'room' => [
                'id' => $room->id,
                'name' => $room->name,
                'owner' => $owner
            ],
            'members' => $members,
            'total_members' => $members->count(),
            'moderators_count' => $members->where('role', 'moderator')->count(),
            'regular_members_count' => $members->where('role', 'member')->count()
        ]);
    }

    /**
     * Check if user is a member of a specific room
     * 
     * @param int $roomId
     * @return \Illuminate\Http\JsonResponse
     */
    public function checkMembership($roomId)
    {
        $user = Auth::user();
        $room = Room::find($roomId);
        
        if (!$room) {
            return response()->json([
                'success' => false,
                'message' => 'Room not found'
            ], 404);
        }
        
        // Check if user is the owner
        $isOwner = $room->owner_id === $user->id;
        
        // Check if user is a member
        $membership = RoomMember::where('room_id', $roomId)
            ->where('user_id', $user->id)
            ->first();
        
        $isMember = false;
        $membershipStatus = null;
        $role = null;
        
        if ($membership) {
            $membershipStatus = $membership->status;
            $role = $membership->role;
            $isMember = $membership->status === 'approved';
        }
        
        return response()->json([
            'success' => true,
            'is_member' => $isMember || $isOwner,
            'is_owner' => $isOwner,
            'membership_status' => $membershipStatus,
            'role' => $isOwner ? 'owner' : $role,
            'room_id' => $roomId
        ]);
    }

    /**
     * Bulk check membership for multiple rooms at once
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function checkBulkMembership(Request $request)
    {
        $request->validate([
            'room_ids' => 'required|array',
            'room_ids.*' => 'integer|exists:rooms,id'
        ]);

        $user = Auth::user();
        $roomIds = $request->room_ids;

        // Get all rooms
        $rooms = Room::whereIn('id', $roomIds)->get()->keyBy('id');

        // Get all memberships for these rooms
        $memberships = RoomMember::where('user_id', $user->id)
            ->whereIn('room_id', $roomIds)
            ->get()
            ->keyBy('room_id');

        $results = [];
        foreach ($roomIds as $roomId) {
            $room = $rooms->get($roomId);
            $membership = $memberships->get($roomId);
            
            if (!$room) {
                $results[$roomId] = [
                    'success' => false,
                    'message' => 'Room not found'
                ];
                continue;
            }

            $isOwner = $room->owner_id === $user->id;
            $isMember = $membership && $membership->status === 'approved';

            $results[$roomId] = [
                'success' => true,
                'is_member' => $isMember || $isOwner,
                'is_owner' => $isOwner,
                'membership_status' => $membership ? $membership->status : null,
                'role' => $isOwner ? 'owner' : ($membership ? $membership->role : null),
                'room_id' => $roomId
            ];
        }

        return response()->json([
            'success' => true,
            'memberships' => $results
        ]);
    }
}
