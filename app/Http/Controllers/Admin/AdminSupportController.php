<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Models\SupportMessage;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class AdminSupportController extends Controller
{
    /**
     * Get all support tickets with filtering and pagination
     */
    public function index(Request $request)
    {
        try {
            $query = SupportTicket::with(['user:id,name,email', 'assignedAdmin:id,name'])
                ->withCount('messages');

            // Apply filters
            if ($request->has('search') && $request->search) {
                $query->search($request->search);
            }

            if ($request->has('status') && $request->status !== 'All') {
                $query->status($request->status);
            }

            if ($request->has('category') && $request->category !== 'All') {
                $query->category($request->category);
            }

            if ($request->has('priority') && $request->priority !== 'All') {
                $query->priority($request->priority);
            }

            // Sorting
            $sortBy = $request->get('sort_by', 'created_at');
            $sortOrder = $request->get('sort_order', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            // Pagination
            $perPage = $request->get('per_page', 20);
            $tickets = $query->paginate($perPage);

            // Format the response
            $formattedTickets = $tickets->getCollection()->map(function ($ticket) {
                return [
                    'id' => $ticket->id,
                    'ticketNumber' => $ticket->ticket_number,
                    'subject' => $ticket->subject,
                    'description' => $ticket->description,
                    'user' => $ticket->user->email,
                    'userName' => $ticket->user->name,
                    'category' => $ticket->category,
                    'priority' => $ticket->priority,
                    'status' => $ticket->status,
                    'assignedTo' => $ticket->assigned_to,
                    'assignedAdmin' => $ticket->assignedAdmin ? $ticket->assignedAdmin->name : null,
                    'createdAt' => $ticket->created_at->format('Y-m-d H:i:s'),
                    'lastUpdate' => $ticket->updated_at->format('Y-m-d H:i:s'),
                    'messagesCount' => $ticket->messages_count,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'tickets' => $formattedTickets,
                    'pagination' => [
                        'current_page' => $tickets->currentPage(),
                        'last_page' => $tickets->lastPage(),
                        'per_page' => $tickets->perPage(),
                        'total' => $tickets->total(),
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch tickets: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get specific ticket with messages
     */
    public function show($id)
    {
        try {
            $ticket = SupportTicket::with([
                'user:id,name,email',
                'assignedAdmin:id,name',
                'messages.sender:id,name,email,user_type'
            ])->findOrFail($id);

            $formattedTicket = [
                'id' => $ticket->id,
                'ticketNumber' => $ticket->ticket_number,
                'subject' => $ticket->subject,
                'description' => $ticket->description,
                'user' => $ticket->user->email,
                'userName' => $ticket->user->name,
                'category' => $ticket->category,
                'priority' => $ticket->priority,
                'status' => $ticket->status,
                'assignedTo' => $ticket->assigned_to,
                'assignedAdmin' => $ticket->assignedAdmin ? $ticket->assignedAdmin->name : null,
                'createdAt' => $ticket->created_at->format('Y-m-d H:i:s'),
                'lastUpdate' => $ticket->updated_at->format('Y-m-d H:i:s'),
                'messages' => $ticket->messages->map(function ($message) {
                    return [
                        'id' => $message->id,
                        'sender' => $message->sender->name,
                        'senderType' => $message->sender_type,
                        'message' => $message->message,
                        'timestamp' => $message->created_at->format('Y-m-d H:i:s'),
                        'attachment_path' => $message->attachment_path,
                        'attachment_name' => $message->attachment_name,
                        'attachment_url' => $message->attachment_url,
                    ];
                })
            ];

            return response()->json([
                'success' => true,
                'data' => $formattedTicket
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch ticket: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update ticket status
     */
    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:Open,In Progress,Resolved,Closed'
        ]);

        try {
            $ticket = SupportTicket::findOrFail($id);
            $oldStatus = $ticket->status;
            $ticket->status = $request->status;
            $ticket->save();

            return response()->json([
                'success' => true,
                'message' => 'Ticket status updated successfully',
                'data' => [
                    'ticket_id' => $ticket->id,
                    'old_status' => $oldStatus,
                    'new_status' => $ticket->status
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update ticket status: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Assign ticket to admin
     */
    public function assignTicket(Request $request, $id)
    {
        $request->validate([
            'assigned_to' => 'required|string|max:255',
            'assigned_admin_id' => 'nullable|exists:users,id'
        ]);

        try {
            $ticket = SupportTicket::findOrFail($id);
            $ticket->assigned_to = $request->assigned_to;
            $ticket->assigned_admin_id = $request->assigned_admin_id;
            $ticket->save();

            return response()->json([
                'success' => true,
                'message' => 'Ticket assigned successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to assign ticket: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Send reply message
     */
    public function sendReply(Request $request, $id)
    {
        $request->validate([
            'message' => 'nullable|string',
            'attachment' => 'nullable|file|max:10240|mimes:pdf,doc,docx,jpg,jpeg,png,gif'
        ]);

        // Ensure at least one is provided
        if (!$request->has('message') && !$request->hasFile('attachment')) {
            return response()->json([
                'success' => false,
                'message' => 'Either message or attachment is required'
            ], 422);
        }

        try {
            $ticket = SupportTicket::findOrFail($id);
            
            $messageData = [
                'ticket_id' => $ticket->id,
                'sender_id' => auth()->id(),
                'sender_type' => 'admin',
                'message' => $request->message ?? '',
            ];

            // Handle file attachment
            if ($request->hasFile('attachment')) {
                $file = $request->file('attachment');
                $path = $file->store('support_attachments', 'public');
                $messageData['attachment_path'] = $path;
                $messageData['attachment_name'] = $file->getClientOriginalName();
            }

            $message = SupportMessage::create($messageData);

            // Auto-assign ticket to the admin if it's not already assigned
            if (is_null($ticket->assigned_admin_id)) {
                $admin = auth()->user();
                $ticket->assigned_admin_id = $admin->id;
                $ticket->assigned_to = $admin->name;
            }

            // Update ticket status if it's currently Open
            if ($ticket->status === 'Open') {
                $ticket->status = 'In Progress';
            }

            $ticket->save();

            return response()->json([
                'success' => true,
                'message' => 'Reply sent successfully',
                'data' => [
                    'message_id' => $message->id,
                    'timestamp' => $message->created_at->format('Y-m-d H:i:s')
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to send reply: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get support statistics
     */
    public function getStats()
    {
        try {
            $openTickets = SupportTicket::where('status', 'Open')->count();
            $inProgressTickets = SupportTicket::where('status', 'In Progress')->count();
            $highPriorityTickets = SupportTicket::whereIn('priority', ['High'])->count();
            $todayTickets = SupportTicket::whereDate('created_at', Carbon::today())->count();

            return response()->json([
                'success' => true,
                'data' => [
                    'open_tickets' => $openTickets,
                    'in_progress_tickets' => $inProgressTickets,
                    'high_priority_tickets' => $highPriorityTickets,
                    'today_tickets' => $todayTickets,
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch statistics: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get available priorities
     */
    public function getPriorities()
    {
        $priorities = ['All', 'Low', 'Medium', 'High'];

        return response()->json([
            'success' => true,
            'data' => $priorities
        ]);
    }

    /**
     * Delete ticket (admin only)
     */
    public function destroy($id)
    {
        try {
            $ticket = SupportTicket::findOrFail($id);
            $ticketNumber = $ticket->ticket_number;
            $userEmail = $ticket->user->email;
            
            $ticket->delete();

            return response()->json([
                'success' => true,
                'message' => 'Ticket deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete ticket: ' . $e->getMessage()
            ], 500);
        }
    }
} 