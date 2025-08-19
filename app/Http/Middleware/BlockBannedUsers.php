<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BlockBannedUsers
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        if (Auth::check() && Auth::user()->status === 'banned') {
            Auth::logout();
            return response()->json([
                'status' => 'error',
                'message' => 'Your account has been banned. Please contact support.'
            ], 403);
        }
        return $next($request);
    }
} 