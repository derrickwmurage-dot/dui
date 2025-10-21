<?php

namespace App\Livewire;

use Livewire\Component;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Firestore;
use Kreait\Firebase\Auth;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Mary\Traits\Toast;
use Illuminate\Support\Facades\Http;
use App\Mail\CollaborativeSpaceApproval;
use Illuminate\Support\Facades\Mail;
use App\Mail\PaymentConfirmation;
use Illuminate\Http\Request;
use App\Mail\CollaborativeSpacesInterest;

class CollaborativeSpaces extends Component
{
    use Toast;
    public $collaborativeSpacesData = [];
    public bool $openCollaborativeSpacesModal = false;
    public $collaborativeSpacesDetailsModal = false;
    public $paymentStatusModal = false;

    public $bookingModal = false;
    public $selectedSpace = null;
    public $modalData = [];
    public $paymentMessage = '';
    public $paymentStatus = '';

    public $selectedSpaceId, $selectedSpaceCost;

    public $selectedDuration = '';
    public $preferredBookingName = '';
    public $bookingDate = '';
    public $bookingFurtherClarifications = '';
    public $existingRequest = null;
    public $approvalStatusModal = false;

    public $collaborativeSpacesPayments = [];

    private $database;

    #[Livewire\Attributes\Url(history: true)]
    public $searchTerm = '';

    // New property to store calculated price
    public $calculatedPrice = 0;

    public function updatedSearchTerm()
    {
        $this->fetchCollaborativeSpacesData();
    }

    // Update price when selectedDuration changes
    public function updatedSelectedDuration()
    {
        $this->calculatePrice();
    }

    public function mount()
    {
        $this->loadPayments();

        if (session()->has('successDetails')) {
            $this->paymentStatus = 'success';
            $this->paymentMessage = 'Your payment was successful.';
            $this->paymentStatusModal = true;
            Log::info('Success details found in session:', session('successDetails'));
        } else {
            Log::info('No success details found in session.');
        }

        if (session()->has('payment_status')) {
            $this->paymentStatus = session('payment_status');
            $this->paymentMessage = session('payment_message');
            $this->paymentStatusModal = true;
            session()->forget(['payment_status', 'payment_message']);
        }

        if (session()->has('success')) {
            $this->success(
                title: session('success'),
                position: 'toast-top toast-center',
                css: 'max-w-[90vw] w-auto',
                timeout: 4000
            );
        }

        if (session()->has('error')) {
            $this->error(
                title: session('error'),
                position: 'toast-top toast-center',
                css: 'max-w-[90vw] w-auto',
                timeout: 4000
            );
        }

        $this->fetchCollaborativeSpacesData();
        $this->loadPayments();
    }

    public function closePaymentStatusModal()
    {
        $this->paymentStatusModal = false;
        $this->paymentMessage = '';
        $this->paymentStatus = '';
    }
    
    public function openCollaborativeSpacesDetails($title)
    {
        $this->selectedSpace = collect($this->collaborativeSpacesData)
            ->firstWhere('title', $title);
            
        $this->loadPayments();
        
        if ($this->selectedSpace) {
            $this->checkExistingRequest($this->selectedSpace['title']);
            $this->collaborativeSpacesDetailsModal = true;
        }
    }

    public function fetchCollaborativeSpaceDetails()
    {
        try {
            $factory = (new Factory)->withServiceAccount(storage_path('firebase/firebase-credentials.json'));
            $firestore = $factory->createFirestore();
    
            $documents = $firestore->database()
                ->collection('CollaborativeSpace')
                ->where('title', '=', $this->selectedSpaceName)
                ->documents();
    
            if ($documents->isEmpty()) {
                $this->selectedSpace = [];
                $this->error(
                    title: 'Collaborative space not found.',
                    position: 'toast-top toast-center',
                    css: 'max-w-[90vw] w-auto',
                    timeout: 4000
                );
            } else {
                foreach ($documents as $document) {
                    $this->selectedSpace = $this->sanitizeData($document->data());
                    $this->selectedSpaceId = $document->id();
                    break;
                }
            }
        } catch (\Exception $e) {
            Log::error('Error fetching collaborative space details: ' . $e->getMessage());
            $this->error(
                title: 'An error occurred. Please try again.',
                position: 'toast-top toast-center',
                css: 'max-w-[90vw] w-auto',
                timeout: 4000
            );
        }
    }

    public function checkExistingRequest($title)
    {
        $userId = session('firebase_user');
    
        try {
            $factory = (new Factory)->withServiceAccount(storage_path('firebase/firebase-credentials.json'));
            $firestore = $factory->createFirestore();
    
            $documents = $firestore->database()
                ->collection('CollaborativeSpacePayments')
                ->where('userId', '=', $userId)
                ->where('studioName', '=', $title)
                ->documents();
    
            foreach ($documents as $document) {
                $this->existingRequest = $this->sanitizeData($document->data());
                break;
            }
    
            if ($this->existingRequest) {
                $days = $this->existingRequest['days'] ?? 0;
                $pricePerMonth = $this->selectedSpace['cost'] ?? 0;
                $pricePerDay = $pricePerMonth / 30;
                $paymentAmount = $pricePerDay * $days;
                $this->existingRequest['calculatedAmount'] = $paymentAmount;
                Log::info('Calculated payment amount:', ['amount' => $paymentAmount]);
            }
    
            Log::info('Existing Request Data:', ['existingRequest' => $this->existingRequest]);
    
        } catch (\Exception $e) {
            Log::error('Error checking existing request: ' . $e->getMessage());
        }
    }

    public function fetchCollaborativeSpacesData()
    {
        try {
            $firebase = (new Factory)
                ->withServiceAccount(storage_path('firebase/firebase-credentials.json'));
            
            $firestore = $firebase->createFirestore();
            $collection = $firestore->database()->collection('CollaborativeSpace');

            $documents = $collection->documents();
            $this->collaborativeSpacesData = [];

            foreach ($documents as $document) {
                $data = $document->data();
                
                $sanitizedData = $this->sanitizeData($data);
                $sanitizedData['id'] = $document->id();
                
                $userId = session('firebase_user');
                $existingRequest = null;
                $requestDocuments = $firestore->database()
                    ->collection('CollaborativeSpacePayments')
                    ->where('userId', '=', $userId)
                    ->where('studioName', '=', $sanitizedData['title'])
                    ->documents();

                foreach ($requestDocuments as $requestDocument) {
                    $existingRequest = $this->sanitizeData($requestDocument->data());
                    break;
                }

                if ($existingRequest) {
                    $days = $existingRequest['days'] ?? 0;
                    $pricePerMonth = $sanitizedData['cost'] ?? 0;
                    $pricePerDay = $pricePerMonth / 30;
                    $paymentAmount = $pricePerDay * $days;
                    $existingRequest['calculatedAmount'] = $paymentAmount;
                }

                $sanitizedData['existingRequest'] = $existingRequest;
                
                if ($this->searchTerm) {
                    $searchTerm = strtolower($this->searchTerm);
                    $titleMatch = isset($sanitizedData['title']) && stripos(strtolower($sanitizedData['title']), $searchTerm) !== false;
                    $descriptionMatch = isset($sanitizedData['description']) && stripos(strtolower($sanitizedData['description']), $searchTerm) !== false;
                    $addressMatch = isset($sanitizedData['address']) && stripos(strtolower($sanitizedData['address']), $searchTerm) !== false;
                    
                    if (!($titleMatch || $descriptionMatch || $addressMatch)) {
                        continue;
                    }
                }
                
                $this->collaborativeSpacesData[] = $sanitizedData;
            }

            Log::info('Final Collaborative Spaces Data:', $this->collaborativeSpacesData);

        } catch (\Exception $e) {
            report($e);
        }
    }

    public function handleCallback(Request $request)
    {
        $reference = $request->query('reference');
        $transactionId = $request->query('transactionId');
        $userId = session('firebase_user');

        try {
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

            $paymentRecord = [
                'complete' => true,
                'paymentDetails' => $verificationData['data'],
            ];

            $firestore->database()
                ->collection('CollaborativeSpacePayments')
                ->document($transactionId)
                ->update($paymentRecord);

            $paymentDetails = $firestore->database()
                ->collection('CollaborativeSpacePayments')
                ->document($transactionId)
                ->snapshot()
                ->data();

            Mail::to(session('user_email'))
            ->bcc(config('mail.admin.to'))
            ->send(new PaymentConfirmation($paymentDetails));

            return redirect()->route('collaborative-spaces')
                ->with('success', 'Payment verified successfully.');
        } catch (\Exception $e) {
            Log::error('Payment callback error: ' . $e->getMessage());
            return redirect()->route('collaborative-spaces')
                ->with('error', 'Payment verification failed. Please try again.');
        }
    }

    protected function sanitizeData($data)
    {
        $sanitized = [];
    
        foreach ($data as $key => $value) {
            if ($value instanceof \DateTimeInterface) {
                $sanitized[$key] = $value->format('Y-m-d H:i:s');
            } elseif (is_array($value)) {
                $sanitized[$key] = $this->sanitizeData($value);
            } elseif (is_object($value)) {
                $sanitized[$key] = method_exists($value, 'format')
                    ? $value->format('Y-m-d H:i:s')
                    : (method_exists($value, '__toString') ? (string)$value : json_encode($value));
            } else {
                $sanitized[$key] = $value;
            }
        }
    
        return $sanitized;
    }

    public function bookCoworkingSpace($id, $cost)
    {
        if ($this->existingRequest && !$this->existingRequest['managerApproval']) {
            $this->approvalStatusModal = true;
            return;
        }
    
        $this->selectedSpaceId = $id;
        $this->selectedSpaceCost = $cost;
        $this->calculatePrice(); // Initial calculation
        $this->bookingModal = true;
    }

    public function closeBookingModal()
    {
        $this->bookingModal = false;
    }

    public function closeApprovalStatusModal()
    {
        $this->approvalStatusModal = false;
    }
    
    // New method to calculate price based on duration
    public function calculatePrice()
    {
        if ($this->selectedSpaceCost && $this->selectedDuration) {
            $pricePerMonth = (float) $this->selectedSpaceCost;
            $pricePerDay = $pricePerMonth / 30;
            $this->calculatedPrice = $pricePerDay * (int) $this->selectedDuration;
        } else {
            $this->calculatedPrice = 0;
        }
    }

    public function saveBookingDetails()
    {
        try {
            $this->validate([
                'preferredBookingName' => 'required',
                'bookingDate' => 'required|date|after_or_equal:today',
                'selectedDuration' => 'required',
                'bookingFurtherClarifications' => 'nullable'
            ]);
    
            $userId = session('firebase_user');
            if (!$userId) {
                throw new \Exception('User not authenticated');
            }
    
            $factory = (new Factory)->withServiceAccount(storage_path('firebase/firebase-credentials.json'));
            $firestore = $factory->createFirestore();
    
            $this->calculatePrice();
            $data = [
                'userId' => $userId,
                'userName' => $this->preferredBookingName,
                'studioName' => $this->selectedSpace['title'],
                'startDate' => Carbon::parse($this->bookingDate),
                'days' => (int) $this->selectedDuration,
                'amount' => $this->calculatedPrice,
                'additionalInfo' => $this->bookingFurtherClarifications,
                'timestamp' => Carbon::now(),
                'complete' => false,
                'managerApproval' => false,
                'createdAt' => Carbon::now(),
            ];
    
            $documentRef = $firestore->database()
                ->collection('CollaborativeSpacePayments')
                ->add($data);
    
            $documentId = $documentRef->id();
    
            $spaceEmail = $this->selectedSpace['email'];
            Mail::to($spaceEmail)
                ->bcc(config('mail.admin.to'))
                ->send(new CollaborativeSpacesInterest($data, $documentId)); // Pass $documentId
    
            $this->success(
                title: 'Booking request submitted successfully',
                position: 'toast-top toast-center',
                css: 'max-w-[90vw] w-auto',
                timeout: 4000
            );
            $this->bookingModal = false;
            $this->approvalStatusModal = true;
            $this->loadPayments();
    
            session(['booking_document_id' => $documentId]);
    
        } catch (\Exception $e) {
            Log::error('Booking failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->error(
                title: 'Failed to submit booking request',
                position: 'toast-top toast-center',
                css: 'max-w-[90vw] w-auto',
                timeout: 4000
            );
        }
    }

    public function initiatePayment($title)
    {
        Log::info('Initiating payment', ['title' => $title]);
    
        $userId = session('firebase_user');
        $factory = (new Factory)->withServiceAccount(storage_path('firebase/firebase-credentials.json'));
        $auth = $factory->createAuth();
        
        $user = $auth->getUser($userId);
        $userEmail = $user->email;
        $userName = $user->displayName;
        
        $this->checkExistingRequest($title);
        $amount = $this->existingRequest['calculatedAmount'] ?? 0;
        
        if (!is_numeric($amount) || $amount <= 0) {
            Log::error('Invalid amount provided for payment.');
            $this->error(
                title: 'Invalid amount provided. Please enter a valid amount.',
                position: 'toast-top toast-center',
                css: 'max-w-[90vw] w-auto',
                timeout: 4000
            );
            return;
        }
    
        $documentId = session('booking_document_id');
        
        $paymentData = [
            'email' => $userEmail,
            'amount' => intval($amount * 100), // Amount in kobo
            'reference' => uniqid('tx_'),
            'callback_url' => route('collaborative-spaces.callback'),
            'metadata' => [
                'user_id' => $userId,
                'title' => $title,
                'document_id' => $documentId,
                'customizations' => [
                    'title' => 'Collaborative Space Payment',
                    'description' => 'Payment for booking collaborative space',
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
                Log::error('Paystack payment initiation error:', $response->json());
                $this->error(
                    title: 'Failed to initiate payment. Please try again.',
                    position: 'toast-top toast-center',
                    css: 'max-w-[90vw] w-auto',
                    timeout: 4000
                );
            }
        } catch (\Exception $e) {
            Log::error('Paystack payment initiation error: ' . $e->getMessage());
            $this->error(
                title: 'An error occurred while initiating payment. Please try again.',
                position: 'toast-top toast-center',
                css: 'max-w-[90vw] w-auto',
                timeout: 4000
            );
        }
    }

    protected function loadPayments()
    {
        try {
            $factory = (new Factory)->withServiceAccount(storage_path('firebase/firebase-credentials.json'));
            $firestore = $factory->createFirestore();
            
            $userId = session('firebase_user');
            
            $payments = $firestore->database()
                ->collection('CollaborativeSpacePayments')
                ->documents();

            $this->collaborativeSpacesPayments = collect($payments->rows())
                ->map(function ($payment) {
                    $startDate = isset($payment['startDate']) 
                        ? $payment['startDate']->get()->format('Y-m-d H:i:s')
                        : null;
                    
                    return [
                        'studioName' => $payment['studioName'] ?? '',
                        'amount' => $payment['amount'] ?? 0,
                        'days' => $payment['days'] ?? 0,
                        'startDate' => $startDate,
                        'managerApproval' => $payment['managerApproval'] ?? null,
                        'complete' => $payment['complete'] ?? false,
                        'userId' => $payment['userId'] ?? '',
                        'timestamp' => isset($payment['timestamp']) 
                            ? $payment['timestamp']->get()->format('Y-m-d H:i:s')
                            : null,
                    ];
                })
                ->toArray();

            Log::info('Loaded payments', ['payments' => $this->collaborativeSpacesPayments]);

        } catch (\Exception $e) {
            Log::error('Error loading payments', ['error' => $e->getMessage()]);
            $this->collaborativeSpacesPayments = [];
        }
    }

    public function render()
    {
        return view('livewire.collaborative-spaces', [
            'showPaymentStatus' => $this->paymentStatusModal,
            'paymentMessage' => $this->paymentMessage,
            'paymentStatus' => $this->paymentStatus
        ]);
    }
}