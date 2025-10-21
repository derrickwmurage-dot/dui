<?php

namespace App\Http\Controllers;

use App\Services\PaystackService;
use Kreait\Firebase\Factory;
use Illuminate\Http\Request;
use Carbon\Carbon;

class PaymentController extends Controller
{
    protected PaystackService $paystackService;

    public function __construct(PaystackService $paystackService)
    {
        $this->paystackService = $paystackService;
    }

    public function initiatePayment(Request $request)
    {
        $data = [
            'email' => $request->input('customer.email'),
            'amount' => $request->input('amount') * 100, // Paystack expects amount in kobo
            'reference' => $request->input('tx_ref'),
            'callback_url' => $request->input('redirect_url'),
        ];

        $response = $this->paystackService->initiatePayment($data);

        if ($response['status'] === true) {
            return response()->json([
                'status' => true,
                'message' => 'Authorization URL created',
                'data' => [
                    'authorization_url' => $response['data']['authorization_url'],
                    'access_code' => $response['data']['access_code'],
                    'reference' => $response['data']['reference'],
                ],
            ]);
        } else {
            return response()->json($response, 500);
        }
    }

    public function verifyPayment($reference)
    {
        $response = $this->paystackService->verifyPayment($reference);

        if ($response['status'] === true) {
            $this->storePaymentDetails($response['data']);
            return redirect()->route('home')->with('success', 'Payment verified successfully.');
        } else {
            return redirect()->route('home')->with('error', 'Payment verification failed.');
        }
    }

    protected function storePaymentDetails($paymentData)
    {
        $firebase = (new Factory)->withServiceAccount(storage_path('firebase/firebase-credentials.json'));
        $firestore = $firebase->createFirestore();

        $paymentRecord = [
            'amount' => $paymentData['amount'] / 100, // Convert back to the original amount
            'created_at' => Carbon::now()->toIso8601String(),
            'currency' => $paymentData['currency'],
            'customer' => [
                'email' => $paymentData['customer']['email'],
                'name' => $paymentData['customer']['first_name'] . ' ' . $paymentData['customer']['last_name'],
            ],
            'payment_url' => $paymentData['authorization_url'],
            'status' => $paymentData['status'],
            'verified_at' => Carbon::now()->toIso8601String(),
            'payment_details' => $paymentData['gateway_response'] ?? '',
        ];

        $documentId = $paymentData['reference'] . '-' . Carbon::now()->format('Y-m-d\TH-i-s-u');
        $firestore->database()->collection('payments')->document($documentId)->set($paymentRecord);
    }
}