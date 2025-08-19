<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\User;
use App\Models\PaymentQrCode;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Services\NotificationService;

class AdminPaymentController extends Controller
{
    protected $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Admin: Get all pending payment requests
     */
    public function getPendingPayments()
    {
        $pendingPayments = Payment::where('payment_status', 'pending')
            ->with('user:id,name,email,balance') // Include user details
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'pending_payments' => $pendingPayments->map(function($payment) {
                return [
                    'id' => $payment->id,
                    'transaction_id' => $payment->transaction_id,
                    'amount' => $payment->amount,
                    'currency' => $payment->currency,
                    'phone_no' => $payment->phone_no,
                    'payment_method' => $payment->payment_method,
                    'created_at' => $payment->created_at,
                    'user' => [
                        'id' => $payment->user->id,
                        'name' => $payment->user->name,
                        'email' => $payment->user->email,
                        'current_balance' => $payment->user->balance
                    ]
                ];
            })
        ]);
    }

    /**
     * Admin: Approve payment and add balance
     */
    public function approvePayment(Request $request)
    {
        $request->validate([
            'payment_id' => 'required|exists:payments,id'
        ]);

        $payment = Payment::where('id', $request->payment_id)
                         ->where('payment_status', 'pending')
                         ->firstOrFail();

        try {
            DB::beginTransaction();

            // Update payment status
            $payment->payment_status = 'completed';
            $payment->save();

            // Add balance to user
            $user = User::findOrFail($payment->user_id);
            $user->balance = $user->balance + $payment->amount;
            $user->save();

            DB::commit();

            // Send notification to user about payment approval
            try {
                $this->notificationService->sendPaymentStatusNotification($payment);
            } catch (\Exception $e) {
                \Log::error('Failed to send payment approval notification', [
                    'payment_id' => $payment->id,
                    'user_id' => $payment->user_id,
                    'error' => $e->getMessage()
                ]);
                // Don't fail the approval if notification fails
            }

            // Log activity to activity logs
            ActivityLog::logActivity(
                auth()->id(),
                'Approved Payment',
                "Payment ID: {$payment->transaction_id}",
                "Approved payment of {$payment->amount} {$payment->currency} for user {$user->email}. User balance updated to {$user->balance}",
                'Payment Management',
                'Medium',
                request()->ip()
            );

            return response()->json([
                'success' => true,
                'message' => 'Payment approved and balance added',
                'payment' => $payment,
                'user' => [
                    'id' => $user->id,
                    'new_balance' => $user->balance
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to approve payment',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Admin: Reject payment
     */
    public function rejectPayment(Request $request)
    {
        $request->validate([
            'payment_id' => 'required|exists:payments,id',
            'reject_reason' => 'required|string|max:500'
        ]);

        $payment = Payment::where('id', $request->payment_id)
                         ->where('payment_status', 'pending')
                         ->firstOrFail();

        $payment->payment_status = 'rejected';
        $payment->reject_reason = $request->reject_reason;
        $payment->rejected_at = now();
        $payment->save();

        // Send notification to user about payment rejection
        try {
            $this->notificationService->sendPaymentStatusNotification($payment);
        } catch (\Exception $e) {
            \Log::error('Failed to send payment rejection notification', [
                'payment_id' => $payment->id,
                'user_id' => $payment->user_id,
                'error' => $e->getMessage()
            ]);
            // Don't fail the rejection if notification fails
        }

        // Log activity to activity logs
        $user = User::find($payment->user_id);
        ActivityLog::logActivity(
            auth()->id(),
            'Rejected Payment',
            "Payment ID: {$payment->transaction_id}",
            "Rejected payment of {$payment->amount} {$payment->currency} for user {$user->email}. Reason: {$request->reject_reason}",
            'Payment Management',
            'Medium',
            request()->ip()
        );

        return response()->json([
            'success' => true,
            'message' => 'Payment rejected successfully',
            'payment' => $payment
        ]);
    }



    /**
     * Admin: Update existing QR code settings
     */
    public function updateQrCode(Request $request, $id)
    {
        \Log::info('=== QR CODE UPDATE BACKEND ===');
        \Log::info('Request Method: ' . $request->method());
        \Log::info('QR Code ID: ' . $id);
        \Log::info('All Request Data: ', $request->all());
        \Log::info('Request Headers: ', $request->headers->all());
        \Log::info('Has Files: ', $request->allFiles());
        \Log::info('Wish Number Value: "' . $request->wish_number . '"');
        \Log::info('Description Value: "' . $request->description . '"');
        \Log::info('Has QR Image: ' . ($request->hasFile('qr_image') ? 'YES' : 'NO'));

        try {
            $request->validate([
                'wish_number' => 'required|string|max:255',
                'qr_image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
                'description' => 'nullable|string|max:500'
            ]);
            \Log::info('Validation passed successfully');
        } catch (\Illuminate\Validation\ValidationException $e) {
            \Log::error('Validation failed: ', $e->errors());
            throw $e;
        }

        try {
            $qrCode = PaymentQrCode::findOrFail($id);
            \Log::info('QR Code found for update', ['existing_qr' => $qrCode->toArray()]);
            
            $data = [
                'wish_number' => $request->wish_number,
                'description' => $request->description
            ];

            // Handle QR image upload
            if ($request->hasFile('qr_image')) {
                \Log::info('New QR image uploaded, deleting old image if exists');
                
                // Delete old image if exists
                if ($qrCode->qr_image && Storage::disk('public')->exists($qrCode->qr_image)) {
                    Storage::disk('public')->delete($qrCode->qr_image);
                    \Log::info('Old QR image deleted', ['old_path' => $qrCode->qr_image]);
                }
                
                $imagePath = $request->file('qr_image')->store('qr_codes', 'public');
                $data['qr_image'] = $imagePath;
                \Log::info('New QR image stored', ['new_path' => $imagePath]);
            }

            $qrCode->update($data);
            \Log::info('QR Code updated successfully', ['updated_qr' => $qrCode->fresh()->toArray()]);

            return response()->json([
                'success' => true,
                'message' => 'QR code settings updated successfully',
                'data' => [
                    'id' => $qrCode->id,
                    'wish_number' => $qrCode->wish_number,
                    'qr_image_url' => $qrCode->qr_image_url,
                    'description' => $qrCode->description,
                    'updated_at' => $qrCode->updated_at
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('QR Code update failed', [
                'id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to update QR code settings',
                'error' => $e->getMessage()
            ], 500);
        }
    }



    /**
     * Admin: Get all QR codes
     */
    public function getQrCodes()
    {
        $qrCodes = PaymentQrCode::orderBy('created_at', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => $qrCodes->map(function($qrCode) {
                return [
                    'id' => $qrCode->id,
                    'wish_number' => $qrCode->wish_number,
                    'qr_image_url' => $qrCode->qr_image_url,
                    'description' => $qrCode->description,
                    'created_at' => $qrCode->created_at,
                    'updated_at' => $qrCode->updated_at
                ];
            })
        ]);
    }

    /**
     * Admin: Get active QR code for frontend
     */
    public function getActiveQrCode()
    {
        $qrCode = PaymentQrCode::getActiveQrCode();

        if (!$qrCode) {
            return response()->json([
                'success' => false,
                'message' => 'No QR code found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $qrCode->id,
                'wish_number' => $qrCode->wish_number,
                'qr_image_url' => $qrCode->qr_image_url,
                'description' => $qrCode->description
            ]
        ]);
    }

    /**
     * Admin: Get payment statistics
     */
    public function getPaymentStatistics()
    {
        try {
            // Total Revenue (all completed payments)
            $totalRevenue = Payment::where('payment_status', 'completed')
                ->sum('amount');

            // Monthly Revenue (current month completed payments)
            $monthlyRevenue = Payment::where('payment_status', 'completed')
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->sum('amount');

            // Active Subscriptions (from subscriptions table)
            $activeSubscriptions = \App\Models\Subscription::where('is_active', true)->count();

            // New Subscribers this month
            $newSubscribers = \App\Models\Subscription::whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->count();

            return response()->json([
                'success' => true,
                'statistics' => [
                    'total_revenue' => (float) $totalRevenue,
                    'monthly_revenue' => (float) $monthlyRevenue,
                    'active_subscriptions' => $activeSubscriptions,
                    'new_subscribers' => $newSubscribers
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch payment statistics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Admin: Get all payments with filters
     */
    public function getAllPayments(Request $request)
    {
        try {
            $query = Payment::with('user:id,name,email')
                ->orderBy('created_at', 'desc');

            // Apply filters
            if ($request->has('status') && $request->status !== 'All') {
                $query->where('payment_status', strtolower($request->status));
            }

            if ($request->has('search') && !empty($request->search)) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('transaction_id', 'like', '%' . $search . '%')
                      ->orWhereHas('user', function($userQuery) use ($search) {
                          $userQuery->where('name', 'like', '%' . $search . '%')
                                   ->orWhere('email', 'like', '%' . $search . '%');
                      });
                });
            }

            $payments = $query->get();

            return response()->json([
                'success' => true,
                'payments' => $payments->map(function($payment) {
                    return [
                        'id' => $payment->id,
                        'userName' => $payment->user->name,
                        'email' => $payment->user->email,
                        'amount' => (float) $payment->amount,
                        'status' => ucfirst($payment->payment_status),
                        'paymentMethod' => ucwords(str_replace('_', ' ', $payment->payment_method)),
                        'transactionId' => $payment->transaction_id,
                        'date' => $payment->created_at->toDateString(),
                        'phoneNo' => $payment->phone_no
                    ];
                })
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch payments',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Admin: Get all subscriptions with filters
     */
    public function getAllSubscriptions(Request $request)
    {
        try {
            $query = \App\Models\Subscription::with('user:id,name,email')
                ->orderBy('created_at', 'desc');

            // Apply filters
            if ($request->has('status') && $request->status !== 'All') {
                if (strtolower($request->status) === 'active') {
                    $query->where('is_active', true);
                } elseif (strtolower($request->status) === 'expired') {
                    $query->where('is_active', false);
                }
            }

            if ($request->has('plan') && $request->plan !== 'All') {
                $query->where('level', strtolower($request->plan));
            }

            if ($request->has('search') && !empty($request->search)) {
                $search = $request->search;
                $query->whereHas('user', function($userQuery) use ($search) {
                    $userQuery->where('name', 'like', '%' . $search . '%')
                             ->orWhere('email', 'like', '%' . $search . '%');
                });
            }

            $subscriptions = $query->get();

            return response()->json([
                'success' => true,
                'subscriptions' => $subscriptions->map(function($subscription) {
                    return [
                        'id' => $subscription->id,
                        'userName' => $subscription->user->name,
                        'email' => $subscription->user->email,
                        'plan' => ucfirst($subscription->level),
                        'status' => $subscription->is_active ? 'Active' : 'Expired',
                        'startDate' => $subscription->start_date,
                        'endDate' => $subscription->end_date,
                        'autoRenew' => false, // This system doesn't have auto-renew
                        'totalPaid' => 0 // Could calculate from related payments
                    ];
                })
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch subscriptions',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
