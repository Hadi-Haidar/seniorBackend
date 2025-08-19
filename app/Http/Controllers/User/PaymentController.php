<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    /**
     * Create a new payment request
     */
    public function createPayment(Request $request)
    {
        $request->validate([
            'amount' => 'required|integer|min:1|max:10',
            'phone_no' => 'required|string',
            'currency' => 'required|string|size:3|in:USD',
            'payment_method' => 'required|string|in:wishmoney',
            'transaction_id' => 'required|string|unique:payments,transaction_id',
        ]);

        $payment = Payment::create([
            'user_id' => Auth::id(),
            'amount' => $request->amount,
            'phone_no' => $request->phone_no,
            'currency' => $request->currency,
            'payment_method' => $request->payment_method,
            'transaction_id' => $request->transaction_id,
            'payment_status' => 'pending'
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Payment request created successfully',
            'payment' => $payment
        ]);
    }



    /**
     * User: Cancel pending payment
     */
    public function cancelPayment(Request $request)
    {
        $request->validate([
            'payment_id' => 'required|exists:payments,id'
        ]);

        $payment = Payment::where('id', $request->payment_id)
                         ->where('user_id', Auth::id())
                         ->where('payment_status', 'pending')
                         ->firstOrFail();

        $payment->payment_status = 'cancelled';
        $payment->canceled_at = now();
        $payment->save();

        return response()->json([
            'success' => true,
            'message' => 'Payment cancelled successfully',
            'payment' => $payment
        ]);
    }

    /**
     * Get user's payment history
     */
    public function getPayments()
    {
        $payments = Payment::where('user_id', Auth::id())
                          ->orderBy('created_at', 'desc')
                          ->get();

        return response()->json([
            'success' => true,
            'payments' => $payments
        ]);
    }


}
