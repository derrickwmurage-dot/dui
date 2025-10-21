<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Kreait\Firebase\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;
use App\Mail\CollaborativeSpaceBookingConfirmed;
use App\Mail\CollaborativeSpaceBookedAdminNotification;

class CollaborativeSpacesPaymentController extends Controller
{
    public function initiatePayment(Request $request)
    {
        try {
            $userId = $request->query('userId');
            $amount = $request->query('amount');
            $id = $request->query('id');

            if (empty($userId)) {
                return redirect()->route('collaborative-spaces')->with('error', 'User ID is not set. Please log in again.');
            }

            // Fetch user email from Firebase Authentication
            $firebase = (new Factory)->withServiceAccount(storage_path('firebase/firebase-credentials.json'));
            $auth = $firebase->createAuth();
            $user = $auth->getUser($userId);
            $userEmail = $user->email;

            if (empty($userEmail)) {
                return redirect()->route('collaborative-spaces')->with('error', 'User email not found.');
            }

            // Prepare payment data for Paystack
            $paymentData = [
                'email' => $userEmail,
                'amount' => $amount * 100, // Paystack expects amount in kobo
                'reference' => 'collab-' . time() . '-' . $id,
                'callback_url' => route('collaborative-spaces.payment.callback', ['id' => $id]),
                'metadata' => [
                    'space_id' => $id,
                    'user_id' => $userId
                ]
            ];

            // Make direct request to Paystack
            $response = Http::withToken(config('services.paystack.secret'))
                ->post('https://api.paystack.co/transaction/initialize', $paymentData);

            if (!$response->successful()) {
                Log::error('Paystack payment initiation failed', [
                    'response' => $response->json(),
                    'status' => $response->status()
                ]);
                return redirect()->route('collaborative-spaces')
                    ->with('error', 'Failed to initiate payment. Please try again.');
            }

            $responseData = $response->json();
            
            if (!isset($responseData['data']['authorization_url'])) {
                Log::error('Paystack authorization URL not found in response', ['response' => $responseData]);
                return redirect()->route('collaborative-spaces')
                    ->with('error', 'Invalid payment response. Please try again.');
            }

            // Update the existing payment record in Firestore
            $firestore = $firebase->createFirestore();
            $paymentRecord = [
                'amount' => $amount,
                'created_at' => Carbon::now()->toIso8601String(),
                'currency' => 'USD',
                'customer' => [
                    'email' => $userEmail,
                    'name' => $user->displayName ?? $userEmail,
                ],
                'payment_url' => $responseData['data']['authorization_url'],
                'status' => 'pending',
                'space_id' => $id,
                'user_id' => $userId
            ];

            $firestore->database()
                ->collection('CollaborativeSpacePayments')
                ->document($id)
                ->update($paymentRecord);

            // Redirect to Paystack payment page
            return redirect()->away($responseData['data']['authorization_url']);

        } catch (\Exception $e) {
            Log::error('Payment initiation error: ' . $e->getMessage());
            return redirect()->route('collaborative-spaces')
                ->with('error', 'An error occurred while initiating payment. Please try again.');
        }
    }

    public function handleCallback(Request $request)
    {
        try {
            $reference = $request->query('reference');
            Log::info('Payment callback initiated with reference: ' . $reference);
    
            // Verify the transaction with Paystack
            $response = Http::withToken(config('services.paystack.secret'))
                ->get("https://api.paystack.co/transaction/verify/{$reference}");
    
            $verificationData = $response->json();
            Log::info('Paystack verification response: ' . json_encode($verificationData));
    
            if (!$response->successful() || 
                !isset($verificationData['data']['status']) || 
                $verificationData['data']['status'] !== 'success') {
                Log::error('Payment verification failed', ['response' => $verificationData]);
                throw new \Exception('Payment verification failed');
            }
    
            $factory = (new Factory)->withServiceAccount(storage_path('firebase/firebase-credentials.json'));
            $firestore = $factory->createFirestore();
    
            // Update the payment record
            $paymentRecord = [
                'status' => 'success',
                'verified_at' => Carbon::now()->toIso8601String(),
                'payment_details' => $verificationData['data']
            ];
    
            $firestore->database()
                ->collection('payments')
                ->document($reference)
                ->update($paymentRecord);
            Log::info('Payment record updated in Firestore for reference: ' . $reference);
    
            // Fetch owner's email using the title from Paystack metadata
            $ownerEmail = null;
            $metadata = $verificationData['data']['metadata'] ?? [];
            Log::info('Paystack metadata: ' . json_encode($metadata));
    
            $spaceTitle = $metadata['title'] ?? null;
            Log::info('Space Title from metadata: ' . ($spaceTitle ?? 'Not found'));
    
            if ($spaceTitle) {
                Log::info('Attempting to query CollaborativeSpace with title: ' . $spaceTitle);
                // Query CollaborativeSpace for a document with matching title
                $spaceQuery = $firestore->database()
                    ->collection('CollaborativeSpace')
                    ->where('title', '=', $spaceTitle)
                    ->limit(1)
                    ->documents();
    
                $documentsFound = false;
                foreach ($spaceQuery as $doc) {
                    $documentsFound = true;
                    Log::info('Document found in CollaborativeSpace: ' . $doc->id());
                    if ($doc->exists()) {
                        $docData = $doc->data();
                        Log::info('Document data: ' . json_encode($docData));
                        $ownerEmail = $docData['email'] ?? null;
                        Log::info('Owner Email fetched from CollaborativeSpace: ' . ($ownerEmail ?? 'Not found'));
                        break;
                    }
                }
    
                if (!$documentsFound) {
                    Log::warning('No documents returned from CollaborativeSpace query for title: ' . $spaceTitle);
                } elseif (!$ownerEmail) {
                    Log::warning('No CollaborativeSpace document found or email missing for title: ' . $spaceTitle);
                }
            } else {
                Log::warning('No title found in Paystack metadata');
            }
    
            // Send confirmation email to the user
            try {
                $userEmail = $verificationData['data']['customer']['email'];
                Log::info('Sending confirmation email to user: ' . $userEmail);
                Mail::to($userEmail)
                ->bcc(config('mail.admin.to'))
                ->send(new CollaborativeSpaceBookingConfirmed($paymentRecord, $verificationData['data']));
                Log::info('User confirmation email sent successfully');
            } catch (\Exception $e) {
                Log::error('Failed to send user confirmation email: ' . $e->getMessage());
            }
    
            // Send notification email to admin and owner
            try {
                $adminEmail = config('mail.admin.to');
                $ccEmails = config('mail.admin.cc');
                Log::info('Admin Email: ' . ($adminEmail ?? 'Not set'));
                Log::info('CC Emails: ' . json_encode($ccEmails ?? []));
                Log::info('Owner Email before sending: ' . ($ownerEmail ?? 'Not set'));
    
                $mail = Mail::to($adminEmail);
    
                // Add CC emails if they exist
                if ($ccEmails) {
                    $mail->cc($ccEmails);
                    Log::info('CC emails added: ' . json_encode($ccEmails));
                }
    
                // Add owner email to CC if it exists
                if ($ownerEmail) {
                    $mail->cc($ownerEmail);
                    Log::info('Owner Email added to CC: ' . $ownerEmail);
                } else {
                    Log::warning('Owner Email not found for this booking, not added to CC');
                }
    
                if ($adminEmail) {
                    Log::info('Sending admin/owner notification email');
                    $mail->send(new CollaborativeSpaceBookedAdminNotification($paymentRecord, $verificationData['data'], $userEmail));
                    Log::info('Admin/owner notification email sent successfully');
                } else {
                    Log::warning('No admin email configured, skipping email send');
                }
            } catch (\Exception $e) {
                Log::error('Failed to send admin/owner notification email: ' . $e->getMessage());
            }
    
            Log::info('Callback completed successfully for reference: ' . $reference);
            return redirect()->route('collaborative-spaces')
                ->with('payment_status', 'success')
                ->with('payment_message', 'Your payment was successful! Your booking has been confirmed.');
    
        } catch (\Exception $e) {
            Log::error('Payment callback error: ' . $e->getMessage(), ['exception' => $e]);
            return redirect()->route('collaborative-spaces')
                ->with('error', 'An error occurred while processing your payment.');
        }
    }
}