<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Kreait\Firebase\Factory;
use Google\Cloud\Firestore\FieldValue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Mail\InvestmentRequest;
use App\Mail\MarketplacePaymentConfirmation as PaymentConfirmation;
use App\Mail\AdminMarketplacePaymentNotification; // New Mailable
use Illuminate\Support\Facades\Http;

class MarketplacePaymentController extends Controller
{
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
    
            // Extract necessary values from the verification data
            $amount = $verificationData['data']['amount'] / 100;
            $currency = $verificationData['data']['currency'];
            $customerEmail = $verificationData['data']['customer']['email'];
            $customerName = $verificationData['data']['customer']['first_name'] . ' ' . $verificationData['data']['customer']['last_name'];
            $metadata = $verificationData['data']['metadata'];
    
            Log::info('Paystack metadata:', $metadata);
    
            if (!is_array($metadata)) {
                throw new \Exception('Invalid metadata format');
            }
    
            if (empty($metadata['modalInvestData']) || !is_array($metadata['modalInvestData'])) {
                throw new \Exception('modalInvestData is empty or not an array');
            }
    
            $factory = (new Factory)->withServiceAccount(base_path(env('FIREBASE_CREDENTIALS')));
            $firestore = $factory->createFirestore();
        
            // Store the payment details in PaystackPayments document
            $paymentRecord = [
                'amount' => $amount,
                'createdAt' => now()->toIso8601String(),
                'currency' => $currency,
                'customerEmail' => $customerEmail,
                'customerName' => $customerName,
                'failReason' => '',
                'paymentDetails' => 'Payment for investment in marketplace item',
                'refId' => $reference,
                'userId' => $userId,
                'approved' => true,
            ];
        
            $firestore->database()
                ->collection('PaystackPayments')
                ->document($reference)
                ->set($paymentRecord);
        
            // Check if the marketplace document exists
            $marketplaceDocRef = $firestore->database()->collection('Marketplace')->document($metadata['modalInvestData']['id']);
            $marketplaceDocSnapshot = $marketplaceDocRef->snapshot();
            
            if ($marketplaceDocSnapshot->exists()) {
                $investors = $marketplaceDocSnapshot->data()['investors'] ?? [];
                foreach ($investors as &$investor) {
                    if ($investor['investorId'] === $userId) {
                        $marketplaceDocRef->update([
                            ['path' => 'investors', 'value' => FieldValue::arrayRemove([$investor])]
                        ]);
                        $investor['investmentComplete'] = true;
                        $marketplaceDocRef->update([
                            ['path' => 'investors', 'value' => FieldValue::arrayUnion([$investor])]
                        ]);
                        break;
                    }
                }
            } else {
                throw new \Exception('Marketplace document not found');
            }
    
            // Send email notifications
            $investorEmail = $customerEmail;
            $investorName = $customerName;
            $ownerEmail = $metadata['modalInvestData']['creatorEmail'];
            $ownerName = $metadata['modalInvestData']['creatorName'];
            $marketplaceItemName = $metadata['modalInvestData']['title'];
    
            $investmentDetails = [
                'ownerName' => $ownerName,
                'marketplaceItemName' => $marketplaceItemName,
                'investorName' => $investorName,
                'investmentAmount' => $amount,
                'equity' => $metadata['investmentDetails']['equity'],
                'extraOfferings' => $metadata['investmentDetails']['extraOfferings'],
            ];
    
            // Send email to the investor
            Mail::to($investorEmail)
            ->bcc(config('mail.admin.to'))
            ->send(new PaymentConfirmation($investmentDetails));
    
            // Send email to the owner
            Mail::to($ownerEmail)
            ->bcc(config('mail.admin.to'))
            ->send(new InvestmentRequest($investmentDetails));
    
            // Send email to admin
            try {
                $adminEmail = config('mail.admin.to');
                $ccEmails = config('mail.admin.cc');

                Log::info('Admin Email: ' . ($adminEmail ?? 'Not set'));
                Log::info('CC Emails: ' . json_encode($ccEmails ?? []));

                if (!$adminEmail) {
                    throw new \Exception('Admin email is not configured in mail settings.');
                }

                Mail::to($adminEmail)
                    ->cc($ccEmails)
                    ->send(new AdminMarketplacePaymentNotification($investmentDetails, $userId, $reference));
            } catch (\Exception $e) {
                Log::error('Failed to send admin payment notification email: ' . $e->getMessage());
            }
    
            // Store success details in session
            session()->flash('successDetails', $investmentDetails);
    
            return redirect()->route('marketplace')
                ->with('success', 'Payment verified successfully.');
        } catch (\Exception $e) {
            Log::error('Payment callback error: ' . $e->getMessage());
            return redirect()->route('marketplace')
                ->with('error', 'Payment verification failed. Please try again.');
        }
    }
}