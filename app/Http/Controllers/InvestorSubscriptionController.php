<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Kreait\Firebase\Factory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class InvestorSubscriptionController extends Controller
{
    public function initiatePayment(Request $request)
    {
        $userId = session('firebase_user');
        $amount = 360 * 100; // Amount in kobo (Paystack expects amount in kobo)

        // Paystack payment setup
        $paymentData = [
            'email' => auth()->user()->email,
            'amount' => $amount,
            'reference' => 'renew_subscription_' . time(),
            'callback_url' => route('investor-subscription.callback'),
            'metadata' => [
                'user_id' => $userId,
                'customizations' => [
                    'title' => 'Renew Subscription',
                    'description' => 'Payment for renewing subscription',
                ],
            ],
        ];

        try {
            $response = Http::withToken(config('services.paystack.secret'))
                ->post('https://api.paystack.co/transaction/initialize', $paymentData);

            if ($response->successful()) {
                $paymentLink = $response->json()['data']['authorization_url'];
                return redirect()->away($paymentLink);
            } else {
                return redirect()->route('to-invest')->with('error', 'Failed to initiate payment. Please try again.');
            }
        } catch (\Exception $e) {
            Log::error('Paystack payment initiation error: ' . $e->getMessage());
            return redirect()->route('to-invest')->with('error', 'An error occurred while initiating payment. Please try again.');
        }
    }

    public function handleCallback(Request $request)
    {
        $reference = $request->query('reference');
        $userId = session('firebase_user');

        try {
            // Verify the transaction with Paystack
            $response = Http::withToken(config('services.paystack.secret'))
                ->get("https://api.paystack.co/transaction/verify/{$reference}");

            $verificationData = $response->json();

            if (!$response->successful() || 
                !isset($verificationData['data']['status']) || 
                $verificationData['data']['status'] !== 'success') {
                throw new \Exception('Payment verification failed');
            }

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

            return redirect()->route('to-invest')->with('success', 'Subscription renewed successfully.');
        } catch (\Exception $e) {
            Log::error('Payment callback error: ' . $e->getMessage());
            return redirect()->route('to-invest')->with('error', 'Payment failed. Please try again.');
        }
    }
}