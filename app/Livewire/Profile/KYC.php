<?php

namespace App\Livewire\Profile;

use Livewire\Component;
use Kreait\Firebase\Factory;
use Mary\Traits\Toast;
use Illuminate\Support\Facades\Log;
use App\Mail\KYCSubmitted;
use Illuminate\Support\Facades\Mail;
use Livewire\Attributes\Reactive;

class KYC extends Component
{
    use Toast;

    public $idPhotoUrl;
    public $profilePhotoUrl;
    
    public $kycSubmitted = false;
    public $kycApproved = true;
    public string $kycTimestamp = ''; // Explicitly type as string
    public bool $paymentModal = false;
    public bool $isSaving = false;

    public function mount()
    {
        $this->kycTimestamp = ''; // Reset on mount
        $this->checkKYCSubmission();
    }

    public function redirectToProfile()
    {
        return redirect()->route('profile');
    }

    public function checkKYCSubmission()
    {
        try {
            $factory = (new Factory)->withServiceAccount(storage_path('firebase/firebase-credentials.json'));
            $firestore = $factory->createFirestore();
            $collection = $firestore->database()->collection('KYC');
            $documents = $collection->where('userId', '=', session('firebase_user'))->documents();

            if ($documents->isEmpty()) {
                $this->kycSubmitted = false;
                $this->kycApproved = false;
                $this->idPhotoUrl = '';
                $this->profilePhotoUrl = '';
                $this->kycTimestamp = '';
            } else {
                $this->kycSubmitted = true;
                $data = $documents->rows()[0]->data();
                Log::info('KYC Data from Firestore:', $data); // Debug raw data

                $this->kycApproved = $data['approved'] ?? false;
                $this->idPhotoUrl = $data['idImageUrl'] ?? '';
                $this->profilePhotoUrl = $data['profileImageUrl'] ?? '';

                // Handle timestamp explicitly
                if (isset($data['timestamp'])) {
                    if ($data['timestamp'] instanceof \Google\Cloud\Core\Timestamp) {
                        $this->kycTimestamp = $data['timestamp']->get()->format('Y-m-d H:i:s');
                    } elseif (is_string($data['timestamp'])) {
                        $this->kycTimestamp = $data['timestamp']; // If already a string
                    } elseif (is_array($data['timestamp']) && !empty($data['timestamp'])) {
                        $this->kycTimestamp = $data['timestamp'][0]; // Take first element if array
                        Log::warning('Timestamp was an array:', $data['timestamp']);
                    } else {
                        $this->kycTimestamp = '';
                        Log::warning('Unexpected timestamp format:', [$data['timestamp']]);
                    }
                } else {
                    $this->kycTimestamp = '';
                }
                Log::info('Set kycTimestamp to:', [$this->kycTimestamp]);
            }
        } catch (\Exception $e) {
            Log::error('Error checking KYC submission: ' . $e->getMessage());
            $this->kycSubmitted = false;
            $this->kycTimestamp = '';
        }
    }

    public function saveKYC()
    {
        // Add debug logging
        Log::info('Attempting to save KYC with URLs:', [
            'idPhotoUrl' => $this->idPhotoUrl,
            'profilePhotoUrl' => $this->profilePhotoUrl
        ]);

        if (empty($this->idPhotoUrl) || empty($this->profilePhotoUrl)) {
            $this->error('Please upload both ID and profile photos.');
            return;
        }
    
        $this->isSaving = true;
    
        try {
            $userId = session('firebase_user');
            $factory = (new Factory)->withServiceAccount(storage_path('firebase/firebase-credentials.json'));
            $firestore = $factory->createFirestore();
            $collection = $firestore->database()->collection('KYC');
    
            $kycData = [
                'userId' => $userId,
                'idImageUrl' => $this->idPhotoUrl,
                'profileImageUrl' => $this->profilePhotoUrl,
                'approved' => true,
                'timestamp' => new \Google\Cloud\Core\Timestamp(new \DateTimeImmutable()),
            ];
    
            $collection->document($userId)->set($kycData, ['merge' => true]);
    
            // Send email to admin after successful KYC submission
            try {
                $adminEmail = config('mail.admin.to');
                $ccEmails = config('mail.admin.cc');
    
                // Log the values for debugging
                Log::info('KYC Submission - Admin Email: ' . ($adminEmail ?? 'Not set'));
                Log::info('KYC Submission - CC Emails: ' . json_encode($ccEmails ?? []));
    
                if (!$adminEmail) {
                    throw new \Exception('Admin email is not configured in mail settings.');
                }
    
                Mail::to($adminEmail)
                    ->cc($ccEmails)
                    ->send(new KYCSubmitted($kycData, $userId));
            } catch (\Exception $e) {
                Log::error('Failed to send KYC submission email: ' . $e->getMessage());
            }
    
            $this->success('KYC documents submitted successfully.');
            $this->checkKYCSubmission();
            $this->redirectToProfile();
        } catch (\Exception $e) {
            Log::error('Error saving KYC: ' . $e->getMessage());
            $this->error('Failed to save KYC: ' . $e->getMessage());
        } finally {
            $this->isSaving = false;
        }
    }

    public function removeImage($type)
    {
        if ($type === 'idPhoto') {
            $this->idPhotoUrl = '';
        } elseif ($type === 'profilePhoto') {
            $this->profilePhotoUrl = '';
        }
        $this->success('Image removed successfully');
    }

    public function approvedInvestorAction($index)
    {
        // Implement if needed
    }

    public function render()
    {
        return view('livewire.profile.k-y-c', [
            'kycTimestamp' => $this->kycTimestamp,
        ]);
    }
}