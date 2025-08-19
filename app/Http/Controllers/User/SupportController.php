<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Models\SupportMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SupportController extends Controller
{
    /**
     * Get user's support tickets
     */
    public function index(Request $request)
    {
        try {
            $query = SupportTicket::where('user_id', auth()->id())
                ->withCount('messages');

            // Apply filters
            if ($request->has('status') && $request->status !== 'All') {
                $query->where('status', $request->status);
            }

            if ($request->has('category') && $request->category !== 'All') {
                $query->where('category', $request->category);
            }

            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('subject', 'LIKE', "%{$search}%")
                      ->orWhere('description', 'LIKE', "%{$search}%")
                      ->orWhere('ticket_number', 'LIKE', "%{$search}%");
                });
            }

            // Sorting
            $query->orderBy('created_at', 'desc');

            // Pagination
            $perPage = $request->get('per_page', 10);
            $tickets = $query->paginate($perPage);

            // Format the response
            $formattedTickets = $tickets->getCollection()->map(function ($ticket) {
                return [
                    'id' => $ticket->id,
                    'ticket_number' => $ticket->ticket_number,
                    'subject' => $ticket->subject,
                    'description' => $ticket->description,
                    'category' => $ticket->category,
                    'priority' => $ticket->priority,
                    'status' => $ticket->status,
                    'assigned_to' => $ticket->assigned_to,
                    'created_at' => $ticket->created_at->format('Y-m-d H:i:s'),
                    'updated_at' => $ticket->updated_at->format('Y-m-d H:i:s'),
                    'messages_count' => $ticket->messages_count,
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
     * Create new support ticket
     */
    public function store(Request $request)
    {
        $request->validate([
            'subject' => 'required|string|max:255',
            'description' => 'required|string',
            'category' => 'required|in:Technical Issues,Subscriptions,Security,General Support,Billing,Account',
            'priority' => 'required|in:Low,Medium,High',
            'attachment' => 'nullable|file|max:10240|mimes:pdf,doc,docx,jpg,jpeg,png,gif'
        ]);

        try {
            // Create the ticket
            $ticketData = [
                'user_id' => auth()->id(),
                'subject' => $request->subject,
                'description' => $request->description,
                'category' => $request->category,
                'priority' => $request->priority,
                'status' => 'Open',
            ];

            $ticket = SupportTicket::create($ticketData);

            // Create initial message
            $messageData = [
                'ticket_id' => $ticket->id,
                'sender_id' => auth()->id(),
                'sender_type' => 'user',
                'message' => $request->description,
            ];

            // Handle file attachment
            if ($request->hasFile('attachment')) {
                $file = $request->file('attachment');
                $path = $file->store('support_attachments', 'public');
                $messageData['attachment_path'] = $path;
                $messageData['attachment_name'] = $file->getClientOriginalName();
            }

            SupportMessage::create($messageData);

            return response()->json([
                'success' => true,
                'message' => 'Support ticket created successfully',
                'data' => [
                    'ticket_id' => $ticket->id,
                    'ticket_number' => $ticket->ticket_number
                ]
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create ticket: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get specific ticket with messages (user can only view their own tickets)
     */
    public function show($id)
    {
        try {
            $ticket = SupportTicket::where('user_id', auth()->id())
                ->with(['messages.sender:id,name,email,user_type'])
                ->findOrFail($id);

            $formattedTicket = [
                'id' => $ticket->id,
                'ticket_number' => $ticket->ticket_number,
                'subject' => $ticket->subject,
                'description' => $ticket->description,
                'category' => $ticket->category,
                'priority' => $ticket->priority,
                'status' => $ticket->status,
                'assigned_to' => $ticket->assigned_to,
                'created_at' => $ticket->created_at->format('Y-m-d H:i:s'),
                'updated_at' => $ticket->updated_at->format('Y-m-d H:i:s'),
                'messages' => $ticket->messages->map(function ($message) {
                    return [
                        'id' => $message->id,
                        'sender' => $message->sender->name,
                        'sender_type' => $message->sender_type,
                        'message' => $message->message,
                        'created_at' => $message->created_at->format('Y-m-d H:i:s'),
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
     * Send message to ticket
     */
    public function sendMessage(Request $request, $id)
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
            $ticket = SupportTicket::where('user_id', auth()->id())->findOrFail($id);

            // Don't allow messages on closed tickets
            if ($ticket->status === 'Closed') {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot send messages to closed tickets'
                ], 422);
            }

            $messageData = [
                'ticket_id' => $ticket->id,
                'sender_id' => auth()->id(),
                'sender_type' => 'user',
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

            // Update ticket status if it was resolved
            if ($ticket->status === 'Resolved') {
                $ticket->status = 'In Progress';
                $ticket->save();
            }

            return response()->json([
                'success' => true,
                'message' => 'Message sent successfully',
                'data' => [
                    'message_id' => $message->id,
                    'timestamp' => $message->created_at->format('Y-m-d H:i:s')
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to send message: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get user's support statistics
     */
    public function getStats()
    {
        try {
            $userId = auth()->id();
            
            $openTickets = SupportTicket::where('user_id', $userId)->where('status', 'Open')->count();
            $inProgressTickets = SupportTicket::where('user_id', $userId)->where('status', 'In Progress')->count();
            $resolvedTickets = SupportTicket::where('user_id', $userId)->where('status', 'Resolved')->count();
            $totalTickets = SupportTicket::where('user_id', $userId)->count();

            return response()->json([
                'success' => true,
                'data' => [
                    'open_tickets' => $openTickets,
                    'in_progress_tickets' => $inProgressTickets,
                    'resolved_tickets' => $resolvedTickets,
                    'total_tickets' => $totalTickets,
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
     * Get available priorities for users
     */
    public function getPriorities()
    {
        $priorities = ['Low', 'Medium', 'High'];

        return response()->json([
            'success' => true,
            'data' => $priorities
        ]);
    }

} 