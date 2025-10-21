<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;

class FirebaseAuthMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next)
    {
        // Allow public routes
        // $publicRoutes = ['/', 'login', 'register'];
        // if (in_array($request->path(), $publicRoutes)) {
        //     return $next($request);
        // }
    
        // // Check Firebase authentication
        // if (!session()->has('firebase_user')) {
        //     return redirect()->route('login')
        //         ->with('error', 'Please log in to access this page.');
        // }
    
        return $next($request);
    }
}
