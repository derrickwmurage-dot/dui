<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Kreait\Firebase\Factory;
use Illuminate\Support\Facades\Log;

class SubscriptionController extends Controller
{
    public function handleCallback(Request $request)
    {
        $status = $request->query('status');
        $transactionId = $request->query('transaction_id');
        $userId = session('firebase_user');

        if ($status === 'successful') {
            $factory = (new Factory)->withServiceAccount(base_path(env('FIREBASE_CREDENTIALS')));
            $firestore = $factory->createFirestore();

            // Update the subscription expiry date
            $subscriptionDocRef = $firestore->database()->collection('Subscriptions')->document($userId);
            $subscriptionDocRef->update([
                ['path' => 'expiryDate', 'value' => now()->addMonth()]
            ]);

            // Update the marketplace subscription expiry date
            $marketplaceDocRef = $firestore->database()->collection('Marketplace')->document($userId);
            $marketplaceDocRef->update([
                ['path' => 'subscriptionExpiry', 'value' => now()->addMonth()]
            ]);

            return redirect()->route('marketplace')->with('success', 'Subscription renewed successfully.');
        } else {
            Log::error('Payment failed or was not successful.');
            return redirect()->route('marketplace')->with('error', 'Payment failed. Please try again.');
        }
    }
}
