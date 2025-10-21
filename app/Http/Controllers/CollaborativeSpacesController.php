<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Kreait\Firebase\Factory;
use Google\Cloud\Firestore\FieldValue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Mail;
use App\Mail\CollaborativeSpaceBookingConfirmed;
use App\Mail\CollaborativeSpaceBookedAdminNotification;

class CollaborativeSpacesController extends Controller
{
    public function handleCallback(Request $request)
    {
        $reference = $request->query('reference');
        $userId = session('firebase_user');
        
        try {
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
    
            // Extract necessary values from the verification data
            $amount = $verificationData['data']['amount'] / 100; // Convert back to the original amount
            $currency = $verificationData['data']['currency'];
            $customerEmail = $verificationData['data']['customer']['email'];
            $customerName = $verificationData['data']['customer']['first_name'] . ' ' . $verificationData['data']['customer']['last_name'];
            $metadata = $verificationData['data']['metadata'] ?? [];
            Log::info('Paystack metadata: ' . json_encode($metadata));
    
            // Ensure metadata is an array
            if (!is_array($metadata)) {
                Log::error('Invalid metadata format', ['metadata' => $metadata]);
                throw new \Exception('Invalid metadata format');
            }
    
            // Ensure document_id is not empty
            if (empty($metadata['document_id'])) {
                Log::error('Document ID is empty in metadata');
                throw new \Exception('Document ID is empty');
            }
    
            $factory = (new Factory)->withServiceAccount(storage_path('firebase/firebase-credentials.json'));
            $firestore = $factory->createFirestore();
        
            // Store the payment details in PaystackPayments document
            $paymentRecord = [
                'amount' => $amount,
                'createdAt' => now()->toIso8601String(),
                'currency' => $currency,
                'customerEmail' => $customerEmail,
                'customerName' => $customerName,
                'failReason' => '',
                'paymentDetails' => 'Payment for booking collaborative space',
                'refId' => $reference,
                'userId' => $userId,
                'approved' => true,
            ];
        
            $firestore->database()
                ->collection('PaystackPayments')
                ->document($reference)
                ->set($paymentRecord);
            Log::info('Payment record stored in PaystackPayments for reference: ' . $reference);
        
            // Update the booking document using the document ID
            $bookingDocRef = $firestore->database()->collection('CollaborativeSpacePayments')->document($metadata['document_id']);
            $bookingDocSnapshot = $bookingDocRef->snapshot();
            
            if ($bookingDocSnapshot->exists()) {
                $bookingData = $bookingDocSnapshot->data();
                Log::info('Booking document found: ' . json_encode($bookingData));
                // Update the booking's complete field to true
                $bookingDocRef->update([
                    ['path' => 'complete', 'value' => true]
                ]);
                Log::info('Booking document updated to complete=true for document_id: ' . $metadata['document_id']);
            } else {
                Log::error('Booking document not found for document ID: ' . $metadata['document_id']);
                throw new \Exception('Booking document not found');
            }

            // Fetch owner's email using the title from Paystack metadata
            $ownerEmail = null;
            $spaceTitle = $metadata['title'] ?? null;
            Log::info('Space Title from metadata: ' . ($spaceTitle ?? 'Not found'));

            if ($spaceTitle) {
                Log::info('Attempting to query CollaborativeSpace with title: ' . $spaceTitle);
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
                        Log::info('CollaborativeSpace document data: ' . json_encode($docData));
                        $ownerEmail = $docData['email'] ?? null;
                        Log::info('Owner Email fetched from CollaborativeSpace: ' . ($ownerEmail ?? 'Not found'));
                        break;
                    }
                }

                if (!$documentsFound) {
                    Log::warning('No documents returned from CollaborativeSpace query for title: ' . $spaceTitle);
                } elseif (!$ownerEmail) {
                    Log::warning('No email field found in CollaborativeSpace document for title: ' . $spaceTitle);
                }
            } else {
                Log::warning('No title found in Paystack metadata');
            }
    
            // Store success details in session
            session()->flash('successDetails', $paymentRecord);
            Log::info('Success details stored in session: ' . json_encode($paymentRecord));

            // Send confirmation email to the user
            try {
                Log::info('Sending confirmation email to user: ' . $customerEmail);
                Mail::to($customerEmail)
                ->bcc(config('mail.admin.to'))
                ->send(new CollaborativeSpaceBookingConfirmed($paymentRecord, $verificationData));
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

                if ($adminEmail) {
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
                        Log::warning('Owner Email not found, not added to CC');
                    }

                    Log::info('Sending admin/owner notification email');
                    $mail->send(new CollaborativeSpaceBookedAdminNotification($paymentRecord, $verificationData, $customerEmail));
                    Log::info('Admin/owner notification email sent successfully');
                } else {
                    Log::warning('No admin email configured, skipping email send');
                }
            } catch (\Exception $e) {
                Log::error('Failed to send admin/owner notification email: ' . $e->getMessage());
            }
    
            Log::info('Callback completed successfully for reference: ' . $reference);
            return redirect()->route('collaborative-spaces')
                ->with('success', 'Payment verified successfully.');
        } catch (\Exception $e) {
            Log::error('Payment callback error: ' . $e->getMessage(), ['exception' => $e]);
            return redirect()->route('collaborative-spaces')
                ->with('error', 'Payment verification failed. Please try again.');
        }
    }
}