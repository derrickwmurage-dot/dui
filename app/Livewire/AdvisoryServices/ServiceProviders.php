<?php

namespace App\Livewire\AdvisoryServices;

use Livewire\Component;
use Kreait\Firebase\Factory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Mary\Traits\Toast;
use App\Mail\BookingApprovalRequest;
use Illuminate\Support\Facades\Http;

class ServiceProviders extends Component
{
    use Toast;

    public $serviceProvidersData = []; 
    public $appointmentModal = false;
    public $paymentStatusModal = false;
    public $paymentStatus = '';
    public $paymentMessage = '';
    public $phoneNumber;
    public $appointmentDate, $appointmentTime, $bookingName, $bookingAdditionalInfo;
    public $amount = 1000;
    public $selectedProvider;
    public $existingBooking = false;
    public $serviceType;

    public function mount($serviceType)
    {
        $this->serviceType = $serviceType;
        $this->fetchServiceProvidersData();

        // Check for payment status from session
        if (session()->has('payment_status')) {
            $this->paymentStatus = session('payment_status');
            $this->paymentMessage = session('payment_message');
            $this->paymentStatusModal = true;
            
            // Clear the session data
            session()->forget(['payment_status', 'payment_message']);
        }
    }

    public function requestService($index)
    {
        $this->selectedProvider = $this->serviceProvidersData[$index];
        $this->amount = $this->selectedProvider['price'] ?? 1000;

        // Check for existing booking
        $userId = session('firebase_user');
        $firebase = (new Factory)
            ->withServiceAccount(storage_path('firebase/firebase-credentials.json'));
        $firestore = $firebase->createFirestore();

        $existingBooking = $firestore->database()
            ->collection('ServicePayments')
            ->where('userId', '=', $userId)
            ->where('serviceProviderName', '=', $this->selectedProvider['name'])
            ->where('complete', '=', false)
            ->documents();

        $this->existingBooking = !$existingBooking->isEmpty();

        if ($this->existingBooking) {
            foreach ($existingBooking as $booking) {
                if (!$booking->data()['serviceProviderApproval']) {
                    $this->error(
                        title: 'Please wait for the service provider to approve your booking.',
                        position: 'toast-top toast-center',
                        css: 'max-w-[90vw] w-auto',
                        timeout: 4000
                    );
                    return;
                } else {
                    $this->initiatePayment($booking->data());
                    return;
                }
            }
        } else {
            $this->appointmentModal = true;
        }
    }

    public function initiatePayment($bookingData)
    {
        Log::info('Initiating payment', ['bookingData' => $bookingData]);

        $userId = session('firebase_user');
        $factory = (new Factory)->withServiceAccount(storage_path('firebase/firebase-credentials.json'));
        $auth = $factory->createAuth();
        
        // Fetch user details from Firebase Authentication
        $user = $auth->getUser($userId);
        $userEmail = $user->email;
        
        // Validate the amount
        if (!is_numeric($bookingData['amount']) || $bookingData['amount'] <= 0) {
            Log::error('Invalid amount provided for payment.');
            $this->error(
                title: 'Invalid amount provided. Please enter a valid amount.',
                position: 'toast-top toast-center',
                css: 'max-w-[90vw] w-auto',
                timeout: 4000
            );
            return;
        }
        
        $paymentData = [
            'email' => $userEmail,
            'amount' => intval($bookingData['amount']) * 100, // Amount in kobo
            'reference' => uniqid('tx_'),
            'callback_url' => route('service-providers.callback'), // Ensure this route is defined
            'metadata' => [
                'user_id' => $userId,
                'serviceProviderName' => $bookingData['serviceProviderName'],
                'customizations' => [
                    'title' => 'Service Payment',
                    'description' => 'Payment for booking service',
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
                title: 'An error occurred. Please try again.',
                position: 'toast-top toast-center',
                css: 'max-w-[90vw] w-auto',
                timeout: 4000
            );
        }
    }

    public function bookAppointment()
    {
        $userId = session('firebase_user');
        
        $firebase = (new Factory)
            ->withServiceAccount(storage_path('firebase/firebase-credentials.json'));
        $auth = $firebase->createAuth();
        $user = $auth->getUser($userId);
        $userEmail = $user->email;
    
        // Save booking details to Firestore and capture the document reference
        $firestore = $firebase->createFirestore();
        $servicePaymentData = [
            'userId' => $userId,
            'userName' => $this->bookingName,
            'serviceProviderName' => $this->selectedProvider['name'],
            'amount' => $this->amount,
            'additionalInfo' => $this->bookingAdditionalInfo,
            'serviceDate' => new \DateTime($this->appointmentDate),
            'serviceTime' => $this->appointmentTime,
            'serviceProviderApproval' => false,
            'complete' => false,
            'paymentComplete' => false,
            'timestamp' => new \DateTime(),
        ];
    
        // Add the document and get the reference
        $documentRef = $firestore->database()
            ->collection('ServicePayments')
            ->add($servicePaymentData);
        
        // Get the auto-generated document ID
        $bookingId = $documentRef->id();
    
        // Send booking approval request email
        $bookingDetails = [
            'id' => $bookingId, // Add the document ID here
            'userName' => $this->bookingName,
            'serviceProviderName' => $this->selectedProvider['name'],
            'serviceDate' => $this->appointmentDate,
            'serviceTime' => $this->appointmentTime,
            'additionalInfo' => $this->bookingAdditionalInfo,
        ];
        Mail::to($this->selectedProvider['contact'])
        ->bcc(config('mail.admin.to'))
        ->send(new BookingApprovalRequest($bookingDetails));
    
        $this->appointmentModal = false;
        $this->success(
            title: 'Booking confirmed successfully.',
            position: 'toast-top toast-center',
            css: 'max-w-[90vw] w-auto',
            timeout: 4000
        );
        
        return redirect('/advisory-services/');
    }

    public function fetchServiceProvidersData()
    {
        try {
            $firebase = (new Factory)
                ->withServiceAccount(storage_path('firebase/firebase-credentials.json'));
            
            $firestore = $firebase->createFirestore();
            $document = $firestore->database()->collection('AdvisoryServices')->document($this->serviceType)->snapshot();

            if ($document->exists()) {
                $data = $document->data();
                $this->serviceProvidersData = $data['providers'] ?? []; // Fetch the providers array

                // Check for existing bookings for each provider
                $userId = session('firebase_user');
                foreach ($this->serviceProvidersData as &$provider) {
                    $existingBooking = $firestore->database()
                        ->collection('ServicePayments')
                        ->where('userId', '=', $userId)
                        ->where('serviceProviderName', '=', $provider['name'])
                        ->where('complete', '=', false)
                        ->documents();

                    $provider['existingBooking'] = !$existingBooking->isEmpty();
                }
            } else {
                $this->serviceProvidersData = []; // Handle case where the document does not exist
            }

            // Debugging: Log the final array
            Log::info('Service Providers Data:', $this->serviceProvidersData);

        } catch (\Exception $e) {
            // Log or handle the error
            report($e);
        }
    }

    public function closeAppointmentModal()
    {
        $this->appointmentModal = false;
    }

    public function closePaymentStatusModal()
    {
        $this->paymentStatusModal = false;
        $this->paymentMessage = '';
        $this->paymentStatus = '';
    }

    public function render()
    {
        return view('livewire.advisory-services.service-providers');
    }
}