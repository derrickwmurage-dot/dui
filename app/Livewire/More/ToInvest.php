<?php

namespace App\Livewire\More;

use Livewire\Component;
use Kreait\Firebase\Factory;
use Mary\Traits\Toast;
use Illuminate\Support\Facades\Log;
use App\Mail\InvestorApplicationSubmitted;
use App\Mail\InvestorApplicationUpdated; // New Mailable for updates
use Illuminate\Support\Facades\Mail;

class ToInvest extends Component
{
    use Toast;

    public $countryPhoneCodes = [];
    public $subscriptionExpiredModal = false;
    public $isSubscriptionActive = false;
    public $renewSubscriptionButton;
    public $sourceOfFunds_url, $proofOfResidence_url;
    public $industryToInvest = [];
    public $level = [];
    public $amount = [];
    public $firstName, $secondName, $age, $email, $phone, $company, $industry, $field, $workTitle, $website, $referral, $details; 
    public $country = '+254';

    public function mount()
    {
        $this->countryPhoneCodes = $this->getCountryPhoneCodes();
        $this->loadExistingData();
        $this->checkSubscriptionStatus();
        
        if (session('success')) {
            $this->success(
                title: session('success'),
                position: 'toast-top toast-center',
                css: 'max-w-[90vw] w-auto',
                timeout: 4000
            );
        }
        if (session('error')) {
            $this->error(
                title: session('error'),
                position: 'toast-top toast-center',
                css: 'max-w-[90vw] w-auto',
                timeout: 4000
            );
        }
    }

    private function loadExistingData()
    {
        try {
            $userId = session('firebase_user');
            if (!$userId) {
                return;
            }

            $factory = (new Factory)->withServiceAccount(storage_path('firebase/firebase-credentials.json'));
            $firestore = $factory->createFirestore();
            $doc = $firestore->database()->collection('InvestorApplication')->document($userId)->snapshot();

            if ($doc->exists()) {
                $data = $doc->data();
                
                $this->firstName = $data['firstName'] ?? '';
                $this->secondName = $data['secondName'] ?? '';
                $this->age = $data['age'] ?? '';
                $this->email = $data['email'] ?? '';
                $this->phone = $data['phone'] ?? '';
                $this->company = $data['company'] ?? '';
                $this->industry = $data['industry'] ?? '';
                $this->field = $data['field'] ?? '';
                $this->workTitle = $data['work'] ?? '';
                $this->website = $data['website'] ?? '';
                $this->referral = $data['referral'] ?? '';
                $this->details = $data['details'] ?? '';
                $this->country = $data['country'] ?? '';
                $this->sourceOfFunds_url = $data['document'] ?? '';
                $this->proofOfResidence_url = $data['address'] ?? '';
                $this->industryToInvest = $data['industryToInvest'] ?? [];
                $this->level = $data['selectedLevels'] ?? [];
                $this->amount = $data['amount'] ?? [];
            }
        } catch (\Exception $e) {
            Log::error('Error loading existing investor data: ' . $e->getMessage());
        }
    }

    public function submitDetails()
    {
        try {
            $userId = session('firebase_user');
            if (!$userId) {
                throw new \Exception('User not authenticated');
            }
        
            $factory = (new Factory)->withServiceAccount(storage_path('firebase/firebase-credentials.json'));
            $firestore = $factory->createFirestore();
            $investorRef = $firestore->database()->collection('InvestorApplication')->document($userId);
        
            $existingDoc = $investorRef->snapshot();
            $existingData = $existingDoc->exists() ? $existingDoc->data() : [];
            $isUpdate = $existingDoc->exists();
        
            $data = [
                'address' => $this->proofOfResidence_url ?? '',
                'age' => strval($this->age),
                'amount' => $this->amount, // Keep as array
                'approved' => $existingData['approved'] ?? false,
                'company' => $this->company ?? '',
                'country' => $this->country,
                'details' => $this->details ?? '',
                'document' => $this->sourceOfFunds_url ?? '',
                'email' => $this->email,
                'field' => $this->field,
                'firstName' => $this->firstName,
                'industry' => $this->industry ?? '',
                'industryToInvest' => $this->industryToInvest,
                'level' => '',
                'phone' => strval($this->phone),
                'referral' => $this->referral,
                'secondName' => $this->secondName,
                'selectedLevels' => $this->level,
                'subscriptionExpiry' => $existingData['subscriptionExpiry'] ?? 
                    new \Google\Cloud\Core\Timestamp(
                        new \DateTimeImmutable((new \DateTimeImmutable())->modify('+1 month')->format('Y-m-d H:i:s'))
                    ),
                'timestamp' => new \Google\Cloud\Core\Timestamp(
                    new \DateTimeImmutable()
                ),
                'userId' => $userId,
                'verified' => $existingData['verified'] ?? false,
                'website' => $this->website ?? '',
                'work' => $this->workTitle,
            ];
        
            $investorRef->set($data, ['merge' => true]);
        
            try {
                $adminEmail = config('mail.admin.to');
                $ccEmails = config('mail.admin.cc');
        
                Log::info('Admin Email: ' . ($adminEmail ?? 'Not set'));
                Log::info('CC Emails: ' . json_encode($ccEmails ?? []));
        
                if (!$adminEmail) {
                    throw new \Exception('Admin email is not configured in mail settings.');
                }
        
                $creatorName = trim(($this->firstName ?? '') . ' ' . ($this->secondName ?? ''));
                $creatorEmail = $this->email;
        
                if ($isUpdate) {
                    Mail::to($adminEmail)
                        ->cc($ccEmails)
                        ->send(new InvestorApplicationUpdated($data, $creatorName, $creatorEmail));
                } else {
                    Mail::to($adminEmail)
                        ->cc($ccEmails)
                        ->send(new InvestorApplicationSubmitted($data, $creatorName, $creatorEmail));
                }
            } catch (\Exception $e) {
                Log::error('Failed to send investor application email: ' . $e->getMessage());
            }
        
            $this->checkSubscriptionStatus();
        
            $message = $isUpdate ? 
                'Application updated successfully!' : 
                'Application submitted successfully!';
            $this->success(
                title: $message,
                position: 'toast-top toast-center',
                css: 'max-w-[90vw] w-auto',
                timeout: 4000
            );
            return redirect()->route('more.to-invest')->with('success', $message);
        
        } catch (\Exception $e) {
            Log::error('Error submitting investor application: ' . $e->getMessage());
            $this->error(
                title: 'Failed to submit application: ' . $e->getMessage(),
                position: 'toast-top toast-center',
                css: 'max-w-[90vw] w-auto',
                timeout: 4000
            );
        }
    }

    // Other methods remain unchanged
    public function openRenewSubscriptionModal() { $this->subscriptionExpiredModal = true; }
    public function closeSubscriptionExpiredModal() { $this->subscriptionExpiredModal = false; }
    public function renewSubscription() { return redirect()->route('investor-subscription.initiate'); }
    public function getCountryPhoneCodes()
    {
        $path = storage_path('app/countries.json');
        if (file_exists($path)) {
            $json = file_get_contents($path);
            $countries = json_decode($json, true);
            return $countries ?? [];
        }
        Log::error('countries.json file not found at path: ' . $path);
        return [];
    }
    public function checkSubscriptionStatus()
    {
        $userId = session('firebase_user');
        $factory = (new Factory)->withServiceAccount(storage_path('firebase/firebase-credentials.json'));
        $firestore = $factory->createFirestore();
        $subscriptionDoc = $firestore->database()->collection('InvestorApplication')->document($userId)->snapshot();

        if ($subscriptionDoc->exists()) {
            $subscriptionData = $subscriptionDoc->data();
            if (isset($subscriptionData['subscriptionExpiry']) && $subscriptionData['subscriptionExpiry'] instanceof \Google\Cloud\Core\Timestamp) {
                $expiryDate = $subscriptionData['subscriptionExpiry']->get()->format('Y-m-d H:i:s');
                $currentDate = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
                $this->isSubscriptionActive = $expiryDate > $currentDate;
                $this->renewSubscriptionButton = !$this->isSubscriptionActive;
            }
        }
    }
    public function render() { return view('livewire.more.to-invest'); }
}