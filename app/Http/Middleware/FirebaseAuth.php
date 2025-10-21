<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Kreait\Firebase\Factory;

class FirebaseAuth
{
    public function handle(Request $request, Closure $next)
    {
        // Check if the session has 'firebase_user'
        if (!$request->session()->has('firebase_user')) {
            // Prevent redirect loop by checking the current URL
            if ($request->is('login')) {
                return $next($request); // Allow access to login route
            }

            return redirect('/login'); // Redirect to login if not authenticated
        }

        // Verify Firebase user
        $firebaseUserId = $request->session()->get('firebase_user');
        $factory = (new Factory())->withServiceAccount(storage_path('firebase/firebase-credentials.json'));
        $auth = $factory->createAuth();

        try {
            $auth->getUser($firebaseUserId);
        } catch (\Kreait\Firebase\Exception\Auth\UserNotFound $e) {
            // Clear invalid session to avoid redirect loops
            $request->session()->forget('firebase_user');
            return redirect('/login')->withErrors('Invalid session, please log in again.');
        }

        return $next($request);
    }
}
