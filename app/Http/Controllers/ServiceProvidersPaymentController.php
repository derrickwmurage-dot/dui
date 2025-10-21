<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Kreait\Firebase\Factory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Mail\PaymentConfirmation;
use App\Mail\AdminPaymentNotification;
use Illuminate\Support\Facades\Http;

class ServiceProvidersPaymentController extends Controller
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

            $factory = (new Factory)->withServiceAccount(storage_path('firebase/firebase-credentials.json'));
            $firestore = $factory->createFirestore();
            $database = $firestore->database();
            
            // Find the correct document ID in ServicePayments
            $query = $database->collection('ServicePayments')
                ->where('userId', '=', $userId)
                ->where('complete', '=', false)
                ->documents();

            if ($query->isEmpty()) {
                Log::error('No matching payment document found for userId: ' . $userId);
                throw new \Exception('No matching payment document found');
            }

            $document = $query->rows()[0];
            $documentId = $document->id();
            $paymentDetails = $document->data();
            Log::info('ServicePayments document found: ' . $documentId, ['data' => $paymentDetails]);

            // Update the payment record in Firestore
            $database->collection('ServicePayments')
                ->document($documentId)
                ->update([
                    ['path' => 'paymentComplete', 'value' => true],
                    ['path' => 'complete', 'value' => true],
                ]);
            Log::info('ServicePayments document updated for documentId: ' . $documentId);

            // Fetch the updated payment details
            $updatedPaymentDetails = $database->collection('ServicePayments')
                ->document($documentId)
                ->snapshot()
                ->data();
            Log::info('Updated ServicePayments data: ' . json_encode($updatedPaymentDetails));

            // Prepare payment info for emails
            $paymentInfo = [
                'userName' => $updatedPaymentDetails['userName'] ?? 'N/A',
                'amount' => $updatedPaymentDetails['amount'] ?? 0,
                'serviceProviderName' => $updatedPaymentDetails['serviceProviderName'] ?? 'N/A',
                'serviceDate' => isset($updatedPaymentDetails['serviceDate']) && $updatedPaymentDetails['serviceDate'] instanceof \Google\Cloud\Core\Timestamp ? $updatedPaymentDetails['serviceDate']->get()->format('Y-m-d') : 'N/A',
                'serviceTime' => $updatedPaymentDetails['serviceTime'] ?? 'N/A',
                'reference' => $reference
            ];

            // Fetch service provider's email from AdvisoryServices
            $providerEmail = null;
            $serviceProviderName = $updatedPaymentDetails['serviceProviderName'] ?? null;
            Log::info('Service Provider Name from payment: ' . ($serviceProviderName ?? 'Not found'));

            if ($serviceProviderName) {
                // Query AdvisoryServices collection (assuming "Mentorship" is the relevant document)
                $serviceDoc = $database->collection('AdvisoryServices')
                    ->document('Mentorship') // Adjust if the document name varies
                    ->snapshot();

                if ($serviceDoc->exists()) {
                    $serviceData = $serviceDoc->data();
                    Log::info('AdvisoryServices document data: ' . json_encode($serviceData));
                    
                    $providers = $serviceData['providers'] ?? [];
                    Log::info('Providers array: ' . json_encode($providers));

                    foreach ($providers as $provider) {
                        if (isset($provider['name']) && $provider['name'] === $serviceProviderName) {
                            $providerEmail = $provider['contact'] ?? null;
                            Log::info('Provider email found: ' . ($providerEmail ?? 'Not found'));
                            break;
                        }
                    }

                    if (!$providerEmail) {
                        Log::warning('No matching provider found for name: ' . $serviceProviderName);
                    }
                } else {
                    Log::warning('AdvisoryServices document "Mentorship" not found');
                }
            } else {
                Log::warning('No service provider name found in payment details');
            }

            // Send payment confirmation email to user
            try {
                $userEmail = session('user_email');
                Log::info('Sending confirmation email to user: ' . $userEmail);
                Mail::to($userEmail)
                ->bcc(config('mail.admin.to'))
                ->send(new PaymentConfirmation($paymentInfo));
                Log::info('User confirmation email sent successfully');
            } catch (\Exception $e) {
                Log::error('Failed to send user confirmation email: ' . $e->getMessage());
            }

            // Send notification email to admin and service provider
            try {
                $adminEmail = config('mail.admin.to');
                $ccEmails = config('mail.admin.cc');
                Log::info('Admin Email: ' . ($adminEmail ?? 'Not set'));
                Log::info('CC Emails: ' . json_encode($ccEmails ?? []));
                Log::info('Provider Email before sending: ' . ($providerEmail ?? 'Not set'));

                if ($adminEmail) {
                    $mail = Mail::to($adminEmail);

                    // Add CC emails if they exist
                    if ($ccEmails) {
                        $mail->cc($ccEmails);
                        Log::info('CC emails added: ' . json_encode($ccEmails));
                    }

                    // Add provider email to CC if it exists
                    if ($providerEmail) {
                        $mail->cc($providerEmail);
                        Log::info('Provider Email added to CC: ' . $providerEmail);
                    } else {
                        Log::warning('Provider Email not found, not added to CC');
                    }

                    Log::info('Sending admin/provider notification email');
                    $mail->send(new AdminPaymentNotification($paymentInfo));
                    Log::info('Admin/provider notification email sent successfully');
                } else {
                    Log::warning('No admin email configured, skipping email send');
                }
            } catch (\Exception $e) {
                Log::error('Failed to send admin/provider notification email: ' . $e->getMessage());
            }

            // Set session data for payment status
            session()->flash('payment_status', 'success');
            session()->flash('payment_message', 'Payment verified successfully.');
            Log::info('Callback completed successfully for reference: ' . $reference);

            return redirect()->route('advisory-services.new')
                ->with('success', 'Payment verified successfully.');
        } catch (\Exception $e) {
            Log::error('Payment callback error: ' . $e->getMessage(), ['exception' => $e]);
            
            // Set session data for payment status
            session()->flash('payment_status', 'error');
            session()->flash('payment_message', 'Payment verification failed. Please try again.');

            return redirect()->route('home')
                ->with('error', 'Payment verification failed. Please try again.');
        }
    }
}