<?php

namespace App\Livewire;

use Livewire\Component;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Firestore;
use Illuminate\Support\Facades\Log;
use Mary\Traits\Toast;

class AdvisoryServices extends Component
{
    use Toast;
    public $advisoryServicesData = [];
    public $advisoryServiceDetailsModal = false;
    public $selectedAdvisoryService = [];

    public $paymentStatusModal = false;
    public $paymentStatus = '';
    public $paymentMessage = '';

    #[Livewire\Attributes\Url(history: true)]
    public $searchTerm = '';

    public function updatedSearchTerm()
    {
        $this->fetchAdvisoryServicesData();
    }

    public function mount()
    {
        $this->fetchAdvisoryServicesData();

        // Check for payment status from session
        if (session()->has('payment_status')) {
            $this->paymentStatus = session('payment_status');
            $this->paymentMessage = session('payment_message');
            $this->paymentStatusModal = true;
            
            // Clear the session data
            session()->forget(['payment_status', 'payment_message']);
        }
    }

    public function closePaymentStatusModal()
    {
        $this->paymentStatusModal = false;
        $this->paymentMessage = '';
        $this->paymentStatus = '';
    }

    public function fetchAdvisoryServicesData()
    {
        try {
            $firebase = (new Factory)
                ->withServiceAccount(storage_path('firebase/firebase-credentials.json'));
            
            $firestore = $firebase->createFirestore();
            $collection = $firestore->database()->collection('AdvisoryServices');

            $documents = $collection->documents();
            $this->advisoryServicesData = [];

            foreach ($documents as $document) {
                $data = $document->data();
                
                // Sanitize the data for Livewire
                $sanitizedData = $this->sanitizeData($data);
                
                // Add document ID to the data
                $sanitizedData['id'] = $document->id();
                
                // Filter by search term
                if ($this->searchTerm) {
                    $searchTerm = strtolower($this->searchTerm);
                    $titleMatch = isset($sanitizedData['title']) && stripos(strtolower($sanitizedData['title']), $searchTerm) !== false;
                    $descriptionMatch = isset($sanitizedData['description']) && stripos(strtolower($sanitizedData['description']), $searchTerm) !== false;
                    
                    if (!($titleMatch || $descriptionMatch)) {
                        continue;
                    }
                }

                // Debugging: Log the data
                Log::info('Advisory Service Data:', $sanitizedData);
                
                $this->advisoryServicesData[] = $sanitizedData;
            }

            // Debugging: Log the final array
            Log::info('Final Advisory Services Data:', $this->advisoryServicesData);

        } catch (\Exception $e) {
            // Log or handle the error
            report($e);
            $this->error('Error fetching advisory services data');
        }
    }

    public function initiatePayment($id, $amount)
    {
        $userId = session('firebase_user');

        if (empty($userId)) {
            $this->advisoryServiceDetailsModal = false;
            $this->error('User ID is not set. Please log in again.');
            return;
        }

        // Fetch the most recent transaction for the user
        $factory = (new Factory)->withServiceAccount(storage_path('firebase/firebase-credentials.json'));
        $firestore = $factory->createFirestore();
        $collection = $firestore->database()->collection('ServicePayments');
        $documents = $collection->where('userId', '=', $userId)->orderBy('createdAt', 'DESC')->limit(1)->documents();

        if ($documents->isEmpty()) {
            $this->error('No recent transaction found for the user.');
            return;
        }

        $document = $documents->first();
        $transactionId = $document->id();

        // Paystack payment setup
        $paymentData = [
            'email' => auth()->user()->email,
            'amount' => $amount * 100, // Paystack expects amount in kobo
            'reference' => 'service_' . time() . '_' . $transactionId,
            'callback_url' => route('advisory-services.payment.callback', ['transactionId' => $transactionId]),
            'metadata' => [
                'user_id' => $userId,
                'transaction_id' => $transactionId,
                'customizations' => [
                    'title' => 'Advisory Service Payment',
                    'description' => 'Payment for advisory service',
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
                $this->error('Failed to initiate payment. Please try again.');
            }
        } catch (\Exception $e) {
            Log::error('Paystack payment initiation error: ' . $e->getMessage());
            $this->error('An error occurred while initiating payment. Please try again.');
        }
    }

    public function handleCallback(Request $request)
    {
        $reference = $request->query('reference');
        $transactionId = $request->query('transactionId');
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

            $factory = (new Factory)->withServiceAccount(storage_path('firebase/firebase-credentials.json'));
            $firestore = $factory->createFirestore();

            // Update the payment record in ServicePayments collection
            $paymentRecord = [
                'complete' => true,
                'paymentDetails' => $verificationData['data'],
            ];

            $firestore->database()
                ->collection('ServicePayments')
                ->document($transactionId)
                ->update($paymentRecord);

            return redirect()->route('advisory-services')
                ->with('success', 'Payment verified successfully.');
        } catch (\Exception $e) {
            Log::error('Payment callback error: ' . $e->getMessage());
            return redirect()->route('advisory-services')
                ->with('error', 'Payment verification failed. Please try again.');
        }
    }

    protected function sanitizeData($data)
    {
        $sanitized = [];
        
        foreach ($data as $key => $value) {
            // Handle different data types
            if ($value instanceof \DateTimeInterface) {
                // Convert timestamp to string
                $sanitized[$key] = $value->format('Y-m-d H:i:s');
            } elseif (is_array($value)) {
                // Recursively sanitize nested arrays
                $sanitized[$key] = array_map(function($item) {
                    // If nested item is an object with a method to convert to string
                    if (is_object($item) && method_exists($item, 'format')) {
                        return $item->format('Y-m-d H:i:s');
                    }
                    return $item;
                }, $value);
            } elseif (is_object($value)) {
                // Try to convert objects to string or ignore
                $sanitized[$key] = method_exists($value, '__toString') 
                    ? (string)$value 
                    : json_encode($value);
            } else {
                // For simple types, keep as is
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    public function openAdvisoryServiceDetails($id)
    {
        try {
            $firebase = (new Factory)
                ->withServiceAccount(storage_path('firebase/firebase-credentials.json'));
            
            $firestore = $firebase->createFirestore();
            $document = $firestore->database()->collection('AdvisoryServices')->document($id)->snapshot();

            if ($document->exists()) {
                $this->selectedAdvisoryService = $document->data();
            } else {
                $this->selectedAdvisoryService = []; // Handle case where the document does not exist
            }

            // Open the modal
            $this->advisoryServiceDetailsModal = true;

        } catch (\Exception $e) {
            // Log or handle the error
            Log::error('Error fetching advisory service details: ' . $e->getMessage());
            $this->selectedAdvisoryService = []; // Reset selected service on error
        }
    }

    public function openServiceProvidersPage($serviceType)
    {
        return redirect()->route('advisory-services', ['serviceType' => $serviceType]);
    }

    public function render()
    {
        return view('livewire.advisory-services');
    }
}